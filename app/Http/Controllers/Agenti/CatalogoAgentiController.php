<?php

namespace App\Http\Controllers\Agenti;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AutenticaAgente;
use App\Models\ChiaveAgente;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as Rotta;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Il catalogo per gli agenti AI (Web Pro Italia, 09/11/2026): tutte le funzioni del gestionale che un agente può
 * chiamare con la sua chiave, lette dalle rotte stesse dell'applicazione. Non si scrive a mano: una funzione nuova
 * di KondoManager compare qui da sola, con i suoi parametri e, se il controller usa un FormRequest, con i campi del
 * modulo e le loro regole.
 *
 * Per ogni rotta: nome, metodi, percorso con i parametri, se scrive, se restituisce un file, se l'utente della
 * chiave ha il ruolo o il permesso che la rotta chiede, e i campi. Le regole si leggono senza validare niente; se
 * dipendono dalla richiesta (il condominio della rotta, l'utente) e non si possono leggere a vuoto, la rotta dice
 * `regole_dinamiche` e i campi si scoprono dalla pagina del modulo (`create`/`edit`) o dagli errori di una prova.
 */
class CatalogoAgentiController extends Controller
{
    public const VERSIONE = 'agenti-1';

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $utente */
        $utente = $request->user();
        $chiave = $request->attributes->get(AutenticaAgente::ATTRIBUTO);

        if ($request->filled('rotta')) {
            return $this->dettaglio($request, (string) $request->query('rotta'), (array) $request->query('parametri', []));
        }

        // La cache cambia con la versione, il catalogo e l'elenco delle rotte (un aggiornamento che aggiunge rotte non aspetta l'ora).
        $impronta = md5(config('app.version').self::VERSIONE.implode(',', array_keys(Route::getRoutes()->getRoutesByName())));
        $rotte = Cache::remember('agenti.catalogo.'.$impronta, now()->addHour(), fn () => $this->rotte());

        foreach ($rotte as &$rotta) {
            $rotta['consentita'] = $this->consentita($utente, $rotta['accesso'] ?? null);
            if ($chiave instanceof ChiaveAgente && $chiave->sola_lettura && $rotta['scrive']) {
                $rotta['consentita'] = false;
            }
        }
        unset($rotta);

        return response()->json([
            'versione'  => self::VERSIONE,
            'kondo'     => config('app.version'),
            'nome'      => config('app.name'),
            'utente'    => [
                'id'    => $utente->id,
                'nome'  => $utente->name,
                'email' => $utente->email,
                'ruoli' => $utente->getRoleNames()->values(),
            ],
            'chiave'    => $chiave instanceof ChiaveAgente ? ['nome' => $chiave->nome, 'sola_lettura' => $chiave->sola_lettura] : null,
            'rotte'     => array_values($rotte),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Una rotta sola, con i parametri veri (`?rotta=…&parametri[condominio]=2`): i campi dei moduli le cui regole
     * dipendono dal condominio o da un altro modello della rotta si leggono così, con i modelli risolti come nella
     * richiesta vera. Non valida e non scrive niente.
     */
    private function dettaglio(Request $request, string $nome, array $parametri): JsonResponse
    {
        $rotta = Route::getRoutes()->getByName($nome);

        if (! $rotta || AutenticaAgente::vietata($nome)) {
            return response()->json(['message' => 'Funzione sconosciuta o non disponibile agli agenti: '.$nome], 404);
        }

        $mancanti = array_values(array_diff($rotta->parameterNames(), array_keys($parametri)));
        if ($mancanti) {
            return response()->json(['message' => 'Mancano i parametri: '.implode(', ', $mancanti), 'errors' => array_fill_keys($mancanti, ['Obbligatorio.'])], 422);
        }

        try {
            $metodo = array_values(array_diff($rotta->methods(), ['HEAD']))[0] ?? 'GET';
            $finta = Request::create(route($nome, $parametri), $metodo);
            $finta->setUserResolver(fn () => $request->user());
            $trovata = Route::getRoutes()->match($finta);
            $trovata->setContainer(app());
            app('router')->substituteBindings($trovata);
            app('router')->substituteImplicitBindings($trovata);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Uno dei parametri non corrisponde a niente nel gestionale.'], 404);
        }

        [$campi, $dinamiche, $modulo] = $this->campi($rotta, fn () => $trovata);

        return response()->json(array_filter([
            'nome'             => $nome,
            'metodi'           => array_values(array_diff($rotta->methods(), ['HEAD'])),
            'percorso'         => '/'.ltrim($rotta->uri(), '/'),
            'url'              => '/'.ltrim($finta->path(), '/'),
            'campi'            => $campi,
            'modulo'           => $modulo,
            'regole_dinamiche' => $dinamiche ?: null,
        ], fn ($v) => $v !== null), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, array> */
    private function rotte(): array
    {
        $fuori = [];

        foreach (Route::getRoutes()->getRoutes() as $rotta) {
            $nome = $rotta->getName();
            $middleware = $rotta->gatherMiddleware();

            if (! $nome || str_starts_with($nome, 'generated::') || ! in_array('web', $middleware, true)
                || AutenticaAgente::vietata($nome) || in_array('password.recente', $middleware, true)
                || ! $this->autenticata($middleware) || str_starts_with($nome, 'agenti.')) {
                continue;
            }

            $metodi = array_values(array_diff($rotta->methods(), ['HEAD']));
            $scrive = (bool) array_diff($metodi, ['GET', 'OPTIONS']);
            [$campi, $dinamiche, $modulo] = $scrive ? $this->campi($rotta) : [null, false, null];

            $fuori[$nome] = array_filter([
                'nome'             => $nome,
                'metodi'           => $metodi,
                'percorso'         => '/'.ltrim($rotta->uri(), '/'),
                'parametri'        => $rotta->parameterNames(),
                'scrive'           => $scrive,
                'file'             => $this->restituisceFile($nome, $rotta->uri()),
                'accesso'          => $this->accesso($middleware),
                'esercizio'        => in_array(\App\Http\Middleware\EnsureCondominioHasEsercizio::class, $middleware, true) ?: null,
                'campi'            => $campi,
                'modulo'           => $modulo,
                'regole_dinamiche' => $dinamiche ?: null,
                'azione'           => $this->azione($rotta),
            ], fn ($v) => $v !== null && $v !== []);
            $fuori[$nome]['scrive'] = $scrive;
        }

        ksort($fuori);

        return $fuori;
    }

    private function autenticata(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if ($m === 'auth' || str_starts_with($m, 'auth:') || $m === \Illuminate\Auth\Middleware\Authenticate::class) {
                return true;
            }
        }

        return false;
    }

    /** Ruoli e permessi che la rotta chiede (`role_or_permission:a|b`, `permission:x`, `role:y`). */
    private function accesso(array $middleware): ?array
    {
        $accesso = [];

        foreach ($middleware as $m) {
            foreach (['role_or_permission', 'permission', 'role'] as $tipo) {
                $classe = match ($tipo) {
                    'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
                    'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
                    'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
                };
                foreach ([$tipo.':', $classe.':'] as $prefisso) {
                    if (str_starts_with($m, $prefisso)) {
                        $accesso[] = ['tipo' => $tipo, 'valori' => explode('|', explode(',', substr($m, strlen($prefisso)))[0])];
                    }
                }
            }
        }

        return $accesso ?: null;
    }

    private function consentita(User $utente, ?array $accesso): bool
    {
        foreach ($accesso ?? [] as $regola) {
            $ok = false;
            foreach ($regola['valori'] as $valore) {
                try {
                    if (($regola['tipo'] !== 'permission' && $utente->hasRole($valore))
                        || ($regola['tipo'] !== 'role' && $utente->hasPermissionTo($valore))) {
                        $ok = true;
                        break;
                    }
                } catch (Throwable) {
                    // Un permesso che nel database non esiste: per questo utente non vale.
                }
            }
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function restituisceFile(string $nome, string $uri): ?bool
    {
        $ultima = substr($nome, strrpos($nome, '.') + 1);

        return (str_contains($ultima, 'print') || in_array($ultima, ['download', 'modello', 'distinta', 'rapporto', 'copia-autentica'], true)
            || str_ends_with($uri, '.pdf')) ?: null;
    }

    private function azione(Rotta $rotta): ?string
    {
        $azione = $rotta->getActionName();

        return $azione === 'Closure' ? null : class_basename(str_replace('@', '::', $azione));
    }

    /** I campi del modulo dal FormRequest del metodo del controller, se c'è. */
    private function campi(Rotta $rotta, ?Closure $risolviRotta = null): array
    {
        $azione = $rotta->getActionName();
        if ($azione === 'Closure') {
            return [null, false, null];
        }

        [$classe, $metodo] = str_contains($azione, '@') ? explode('@', $azione) : [$azione, '__invoke'];

        try {
            $riflesso = new ReflectionMethod($classe, $metodo);
        } catch (Throwable) {
            return [null, false, null];
        }

        foreach ($riflesso->getParameters() as $parametro) {
            $tipo = $parametro->getType();
            if (! $tipo instanceof ReflectionNamedType || $tipo->isBuiltin()) {
                continue;
            }

            $nomeClasse = $tipo->getName();
            if (! is_subclass_of($nomeClasse, FormRequest::class)) {
                continue;
            }

            $risolutori = $risolviRotta ? [$risolviRotta] : [fn () => null, $this->rottaConModelliVuoti($rotta, $riflesso)];

            foreach ($risolutori as $i => $risolutore) {
                try {
                    /** @var FormRequest $modulo */
                    $modulo = (new ReflectionClass($nomeClasse))->newInstance();
                    $modulo->setContainer(app());
                    $modulo->setUserResolver(fn () => request()->user());
                    $modulo->setRouteResolver($risolutore);
                    $regole = method_exists($modulo, 'rules') ? app()->call([$modulo, 'rules']) : [];

                    // Con i modelli vuoti i campi sono quelli veri; cambiano solo i vincoli `exists` legati agli id.
                    return [$this->regole($regole), $i > 0, class_basename($nomeClasse)];
                } catch (Throwable) {
                    continue;
                }
            }

            return [null, true, class_basename($nomeClasse)];
        }

        return [null, false, null];
    }

    /**
     * Una copia della rotta con ogni parametro risolto in un modello vuoto (id 0) del tipo che il controller chiede:
     * le regole che leggono `$this->route('condominio')->id` si possono leggere anche senza un condominio vero.
     */
    private function rottaConModelliVuoti(Rotta $rotta, ReflectionMethod $riflesso): Closure
    {
        return function () use ($rotta, $riflesso) {
            $parametri = [];
            foreach ($riflesso->getParameters() as $parametro) {
                $tipo = $parametro->getType();
                if ($tipo instanceof ReflectionNamedType && ! $tipo->isBuiltin() && is_subclass_of($tipo->getName(), \Illuminate\Database\Eloquent\Model::class)
                    && in_array($parametro->getName(), $rotta->parameterNames(), true)) {
                    $modello = new ($tipo->getName());
                    $modello->setAttribute($modello->getKeyName(), 0);
                    $parametri[$parametro->getName()] = $modello;
                }
            }
            $copia = clone $rotta;
            (new \ReflectionProperty(Rotta::class, 'parameters'))->setValue($copia, $parametri);

            return $copia;
        };
    }

    private function regole(array $regole): array
    {
        $fuori = [];

        foreach ($regole as $campo => $lista) {
            $lista = is_string($lista) ? explode('|', $lista) : (is_array($lista) ? $lista : [$lista]);
            $fuori[$campo] = array_values(array_filter(array_map(fn ($r) => $this->regola($r), $lista)));
        }

        return $fuori;
    }

    private function regola(mixed $regola): ?string
    {
        if (is_string($regola)) {
            return $regola;
        }

        if ($regola instanceof Closure) {
            return 'controllo';
        }

        if ($regola instanceof \Illuminate\Validation\Rules\Enum) {
            try {
                $tipo = (new \ReflectionProperty($regola, 'type'))->getValue($regola);

                return 'in:'.implode(',', array_map(fn ($c) => $c->value ?? $c->name, $tipo::cases()));
            } catch (Throwable) {
                return 'enum';
            }
        }

        if (is_object($regola) && method_exists($regola, '__toString')) {
            try {
                return (string) $regola;
            } catch (Throwable) {
            }
        }

        return is_object($regola) ? 'regola:'.class_basename($regola) : null;
    }
}

<?php

/**
 * # Teste e millesimi dell'assemblea (Web Pro Italia, 17/11/2026)
 *
 * La promessa: il programma dice «costituita», «approvato» o «respinto» con i quorum dell'art. 1136 c.c. **senza
 * errori di arrotondamento**, e nei casi limite (esattamente 500 millesimi, una testa in meno della maggioranza, la
 * seconda convocazione) dà la risposta giusta. Poi le scelte sulle teste: un condòmino con più unità è una testa sola,
 * i comproprietari di un'unità sono una testa sola rappresentata da uno di loro, la delega conta come presenza.
 */

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\AssembleaPresenza;
use App\Models\Assemblee\AssembleaPunto;
use App\Services\Assemblee\AventiDiritto;
use App\Services\Assemblee\ConteggiAssemblea;
use App\Services\Assemblee\Millesimi;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AssembleeHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(asmAmministratore());
});

/** Quattro condòmini, una unità ciascuno: 400, 300, 200, 100 millesimi. */
function asmQuattro(array $millesimi = ['400', '300', '200', '100'], array $assemblea = []): array
{
    $s = asmCondominio([
        'A' => ['millesimi' => $millesimi[0], 'titolari' => ['Anna Alberti' => 'proprietario']],
        'B' => ['millesimi' => $millesimi[1], 'titolari' => ['Bruno Bassi' => 'proprietario']],
        'C' => ['millesimi' => $millesimi[2], 'titolari' => ['Carla Conti' => 'proprietario']],
        'D' => ['millesimi' => $millesimi[3], 'titolari' => ['Dario Dini' => 'proprietario']],
    ], $assemblea);
    app(AventiDiritto::class)->prepara($s['assemblea']);

    return $s;
}

it('i millesimi diventano interi senza perdere niente, e i confronti con le frazioni sono esatti', function () {
    expect(Millesimi::daValore('333.33333'))->toBe(33333333)
        ->and(Millesimi::daValore('500'))->toBe(50000000)
        ->and(Millesimi::daValore(166.66667))->toBe(16666667)
        ->and(Millesimi::daValore('0.000005'))->toBe(1)
        ->and(Millesimi::inDecimale(33333333))->toBe('333.33333')
        // Due terzi di 1.000: 666,67 basta, 666,66 no.
        ->and(Millesimi::almeno(Millesimi::daValore('666.67'), Millesimi::daValore('1000'), 2, 3))->toBeTrue()
        ->and(Millesimi::almeno(Millesimi::daValore('666.66'), Millesimi::daValore('1000'), 2, 3))->toBeFalse()
        // Il riporto a mille è solo per scrivere: 250 su 999,99 è 250,00.
        ->and(Millesimi::testoSuMille(Millesimi::daValore('250'), Millesimi::daValore('999.99')))->toBe('250,00');
});

it('prima convocazione: costituita con più della metà delle teste e almeno due terzi del valore', function () {
    $s = asmQuattro(['400', '266.67', '233.33', '100']);
    [$a, $b, $c] = [$s['persone']['Anna Alberti'], $s['persone']['Bruno Bassi'], $s['persone']['Carla Conti']];

    // 3 teste su 4 e 400 + 266,67 = 666,67: costituita.
    asmSegna($s['assemblea'], [$a, $b, $c]);
    asmPresenza($s['assemblea'], $c)->forceFill(['tipo' => 'assente'])->save();
    asmSegna($s['assemblea'], [$s['persone']['Dario Dini']]);
    $costituzione = asmCostituzione($s['assemblea']);
    expect($costituzione['teste_presenti'])->toBe(3)
        ->and($costituzione['millesimi_presenti_testo'])->toBe('766,67')
        ->and($costituzione['valida'])->toBeTrue();

    // A e B soltanto: 666,67 millesimi, ma 2 teste su 4 non sono più della metà.
    asmPresenza($s['assemblea'], $s['persone']['Dario Dini'])->forceFill(['tipo' => 'assente'])->save();
    $costituzione = asmCostituzione($s['assemblea']);
    expect($costituzione['valida'])->toBeFalse()
        ->and($costituzione['mancano'])->toBe('Mancano 1 condòmino.');
});

it('prima convocazione: 666,66 millesimi non bastano, 666,67 sì', function () {
    $s = asmCondominio([
        'A' => ['millesimi' => '666.66', 'titolari' => ['Anna Alberti' => 'proprietario']],
        'B' => ['millesimi' => '0.01', 'titolari' => ['Bruno Bassi' => 'proprietario']],
        'C' => ['millesimi' => '0', 'titolari' => ['Carla Conti' => 'proprietario']],
        'D' => ['millesimi' => '0', 'titolari' => ['Dario Dini' => 'proprietario']],
        'E' => ['millesimi' => '333.33', 'titolari' => ['Elena Elli' => 'proprietario']],
    ]);
    app(AventiDiritto::class)->prepara($s['assemblea']);
    $p = $s['persone'];

    // A, B e C: 3 teste su 5 e 666,67 millesimi esatti.
    asmSegna($s['assemblea'], [$p['Anna Alberti'], $p['Bruno Bassi'], $p['Carla Conti']]);
    expect(asmCostituzione($s['assemblea'])['valida'])->toBeTrue();

    // A, C e D: 3 teste su 5 ma 666,66 millesimi: manca un centesimo di millesimo.
    asmPresenza($s['assemblea'], $p['Bruno Bassi'])->forceFill(['tipo' => 'assente'])->save();
    asmSegna($s['assemblea'], [$p['Dario Dini']]);
    $costituzione = asmCostituzione($s['assemblea']);
    expect($costituzione['valida'])->toBeFalse()
        ->and($costituzione['mancano'])->toBe('Mancano 0,01 millesimi.');
});

it('seconda convocazione: basta un terzo delle teste e un terzo del valore', function () {
    $s = asmQuattro(['333.34', '333.33', '233.33', '100'], ['convocazione_tenuta' => 'seconda']);
    $p = $s['persone'];

    // 2 teste su 4 (più di un terzo) con 333,34: costituita.
    asmSegna($s['assemblea'], [$p['Anna Alberti'], $p['Dario Dini']]);
    asmPresenza($s['assemblea'], $p['Dario Dini'])->forceFill(['millesimi' => '0'])->save();
    expect(asmCostituzione($s['assemblea'])['valida'])->toBeTrue();

    // In prima la stessa sala non basta.
    $s['assemblea']->forceFill(['convocazione_tenuta' => 'prima'])->save();
    expect(asmCostituzione($s['assemblea'])['valida'])->toBeFalse();

    // 333,33 è meno di un terzo di 1.000.
    $s['assemblea']->forceFill(['convocazione_tenuta' => 'seconda'])->save();
    asmSegna($s['assemblea'], [$p['Bruno Bassi']]);
    asmPresenza($s['assemblea'], $p['Anna Alberti'])->forceFill(['tipo' => 'assente'])->save();
    expect(asmCostituzione($s['assemblea'])['valida'])->toBeFalse();
});

it('maggioranza ordinaria in prima: esattamente 500 millesimi e più della metà degli intervenuti approvano; 499,99 no', function () {
    $s = asmQuattro(['500', '200', '200', '100']);
    $p = $s['persone'];
    asmSegna($s['assemblea'], array_values($p));
    $punto = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 1, 'titolo' => 'Rendiconto', 'maggioranza' => 'ordinaria']);

    // A (500) e D (100) favorevoli, B contrario, C astenuto: 2 teste su 4 non sono più della metà.
    asmVota($punto, [[$p['Anna Alberti'], 'favorevole'], [$p['Dario Dini'], 'favorevole'], [$p['Bruno Bassi'], 'contrario'], [$p['Carla Conti'], 'astenuto']]);
    $v = asmVotazione($punto);
    expect($v['intervenuti']['teste'])->toBe(4)
        ->and($v['valore_ok'])->toBeTrue()
        ->and($v['teste_ok'])->toBeFalse()
        ->and($v['esito_proposto'])->toBe('respinto');

    // Una testa in più: C favorevole. 3 su 4 e 700 millesimi → approvato.
    asmVota($punto, [[$p['Carla Conti'], 'favorevole']]);
    expect(asmVotazione($punto)['esito_proposto'])->toBe('approvato');

    // Solo A favorevole su due intervenuti (A e un contrario che esce dalla votazione): 1 su 2 non basta.
    $punto->voti()->delete();
    asmVota($punto, [[$p['Anna Alberti'], 'favorevole']]);
    $v = asmVotazione($punto);
    expect($v['intervenuti']['teste'])->toBe(1)->and($v['esito_proposto'])->toBe('approvato'); // 1 su 1, 500 millesimi esatti

    // 499,99 millesimi favorevoli: respinto anche con tutte le teste.
    asmPresenza($s['assemblea'], $p['Anna Alberti'])->forceFill(['millesimi' => '499.99'])->save();
    expect(asmVotazione($punto)['esito_proposto'])->toBe('respinto');
});

it('la maggioranza ordinaria in seconda convocazione chiede un terzo del valore, in prima la metà', function () {
    $s = asmQuattro(['333.34', '266.66', '300', '100'], ['convocazione_tenuta' => 'seconda']);
    $p = $s['persone'];
    asmSegna($s['assemblea'], [$p['Anna Alberti'], $p['Dario Dini'], $p['Bruno Bassi']]);
    $punto = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 1, 'titolo' => 'Manutenzione del cortile', 'maggioranza' => 'ordinaria']);
    // A favorevole (333,34) e D favorevole (100): 2 su 3 intervenuti, 433,34 millesimi.
    asmVota($punto, [[$p['Anna Alberti'], 'favorevole'], [$p['Dario Dini'], 'favorevole'], [$p['Bruno Bassi'], 'contrario']]);

    expect(asmVotazione($punto)['esito_proposto'])->toBe('approvato');

    $s['assemblea']->forceFill(['convocazione_tenuta' => 'prima'])->save();
    expect(asmVotazione($punto)['esito_proposto'])->toBe('respinto');
});

it('art. 1136 c. 4 chiede metà del valore anche in seconda; c. 5 due terzi; l\'unanimità tutti', function () {
    $s = asmQuattro(['400', '266.67', '233.33', '100'], ['convocazione_tenuta' => 'seconda']);
    $p = $s['persone'];
    asmSegna($s['assemblea'], array_values($p));

    $nomina = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 1, 'titolo' => 'Nomina dell\'amministratore', 'maggioranza' => 'art1136c4']);
    asmVota($nomina, [[$p['Anna Alberti'], 'favorevole'], [$p['Dario Dini'], 'favorevole'], [$p['Carla Conti'], 'favorevole'], [$p['Bruno Bassi'], 'contrario']]);
    // 3 su 4 e 733,33: approvato. Senza Carla: 500 millesimi esatti, ma 2 su 4.
    expect(asmVotazione($nomina)['esito_proposto'])->toBe('approvato');

    $innovazione = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 2, 'titolo' => 'Ascensore nuovo', 'maggioranza' => 'art1136c5']);
    // A + B = 666,67 e una terza testa astenuta: 2 su 3 intervenuti, due terzi del valore esatti.
    asmVota($innovazione, [[$p['Anna Alberti'], 'favorevole'], [$p['Bruno Bassi'], 'favorevole'], [$p['Dario Dini'], 'astenuto']]);
    expect(asmVotazione($innovazione)['esito_proposto'])->toBe('approvato');
    asmPresenza($s['assemblea'], $p['Bruno Bassi'])->forceFill(['millesimi' => '266.66'])->save();
    expect(asmVotazione($innovazione)['esito_proposto'])->toBe('respinto');

    $unanime = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 3, 'titolo' => 'Cessione del lastrico', 'maggioranza' => 'unanimita']);
    asmPresenza($s['assemblea'], $p['Bruno Bassi'])->forceFill(['millesimi' => '266.67'])->save();
    asmVota($unanime, [[$p['Anna Alberti'], 'favorevole'], [$p['Bruno Bassi'], 'favorevole'], [$p['Carla Conti'], 'favorevole']]);
    expect(asmVotazione($unanime)['esito_proposto'])->toBe('respinto'); // manca Dario
    asmVota($unanime, [[$p['Dario Dini'], 'favorevole']]);
    expect(asmVotazione($unanime)['esito_proposto'])->toBe('approvato');

    $info = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 4, 'titolo' => 'Varie ed eventuali', 'maggioranza' => 'informativa']);
    expect(asmVotazione($info)['esito_proposto'])->toBe('informativa');
});

it('un condòmino con più unità è una testa sola, con la somma dei millesimi', function () {
    $s = asmCondominio([
        'Appartamento 1' => ['millesimi' => '200', 'titolari' => ['Rossi Mario' => 'proprietario']],
        'Box 1' => ['millesimi' => '30', 'titolari' => ['Rossi Mario' => 'proprietario']],
        'Appartamento 2' => ['millesimi' => '770', 'titolari' => ['Neri Luca' => 'proprietario']],
    ]);
    $esito = app(AventiDiritto::class)->prepara($s['assemblea']);

    expect($esito['aggiunte'])->toBe(2)
        ->and(Millesimi::daValore($s['assemblea']->fresh()->millesimi_totali))->toBe(Millesimi::daValore('1000'));
    $rossi = asmPresenza($s['assemblea'], $s['persone']['Rossi Mario']);
    expect($rossi->millesimi)->toBe('230.00000')
        ->and($rossi->idUnita())->toEqualCanonicalizing([$s['unita']['Appartamento 1']->id, $s['unita']['Box 1']->id]);

    // Preparare di nuovo non duplica, e tiene quello che è stato segnato.
    $rossi->forceFill(['tipo' => 'presente'])->save();
    app(AventiDiritto::class)->prepara($s['assemblea']);
    expect(AssembleaPresenza::where('assemblea_id', $s['assemblea']->id)->count())->toBe(2)
        ->and(asmPresenza($s['assemblea'], $s['persone']['Rossi Mario'])->tipo)->toBe('presente');
});

it('i comproprietari di un\'unità sono una testa sola, rappresentata da uno di loro (quello con la quota più alta)', function () {
    $s = asmCondominio([
        'Appartamento 1' => ['millesimi' => '600', 'titolari' => ['Verdi Paola' => ['proprietario', 40], 'Bianchi Ugo' => ['proprietario', 60]]],
        'Appartamento 2' => ['millesimi' => '400', 'titolari' => ['Neri Luca' => 'proprietario', 'Gialli Inquilino' => 'inquilino']],
    ]);
    app(AventiDiritto::class)->prepara($s['assemblea']);

    $presenze = AssembleaPresenza::where('assemblea_id', $s['assemblea']->id)->get();
    expect($presenze)->toHaveCount(2)
        ->and($presenze->pluck('anagrafica_id')->all())->toContain($s['persone']['Bianchi Ugo']->id)
        ->and($presenze->pluck('anagrafica_id')->all())->not->toContain($s['persone']['Gialli Inquilino']->id);

    // Il rappresentante si cambia fra i comproprietari, non con un estraneo.
    $presenza = asmPresenza($s['assemblea'], $s['persone']['Bianchi Ugo']);
    $indirizzo = route('admin.gestionale.assemblee.presenze.update', ['condominio' => $s['condominio']->id, 'assemblea' => $s['assemblea']->id, 'presenza' => $presenza->id]);
    $this->put($indirizzo, ['anagrafica_id' => $s['persone']['Verdi Paola']->id])->assertSessionHasNoErrors();
    expect($presenza->fresh()->anagrafica_id)->toBe($s['persone']['Verdi Paola']->id);
    $this->put($indirizzo, ['anagrafica_id' => $s['persone']['Neri Luca']->id])->assertSessionHasErrors('anagrafica_id');
});

it('l\'usufrutto: la testa è del nudo proprietario, e l\'usufruttuario può essere scelto come rappresentante', function () {
    $s = asmCondominio([
        'Appartamento 1' => ['millesimi' => '1000', 'titolari' => ['Nudo Piero' => 'nuda_proprietario', 'Usufrutto Lia' => 'usufruttuario']],
    ]);
    app(AventiDiritto::class)->prepara($s['assemblea']);
    $presenza = asmPresenza($s['assemblea'], $s['persone']['Nudo Piero']);

    $this->put(route('admin.gestionale.assemblee.presenze.update', ['condominio' => $s['condominio']->id, 'assemblea' => $s['assemblea']->id, 'presenza' => $presenza->id]), ['anagrafica_id' => $s['persone']['Usufrutto Lia']->id])
        ->assertSessionHasNoErrors();
    expect($presenza->fresh()->anagrafica_id)->toBe($s['persone']['Usufrutto Lia']->id);
});

it('una delega conta come presenza, e il voto del delegato vale per chi lo ha delegato', function () {
    $s = asmQuattro();
    $p = $s['persone'];
    asmSegna($s['assemblea'], [$p['Anna Alberti'], $p['Bruno Bassi']]);
    asmSegna($s['assemblea'], [$p['Carla Conti']], 'delega', 'Bruno Bassi');

    $costituzione = asmCostituzione($s['assemblea']);
    expect($costituzione['teste_presenti'])->toBe(3)
        ->and($costituzione['teste_per_delega'])->toBe(1)
        ->and($costituzione['millesimi_presenti_testo'])->toBe('900,00')
        ->and($costituzione['valida'])->toBeTrue();

    $punto = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 1, 'titolo' => 'Preventivo', 'maggioranza' => 'ordinaria']);
    asmVota($punto, [[$p['Carla Conti'], 'contrario']]);
    $v = asmVotazione($punto);
    expect($v['contrari']['nomi'][0]['delegato'])->toBe('Bruno Bassi');
});

it('chi è uscito non è in sala: la costituzione lo toglie, e «tutti i presenti favorevoli» non lo conta', function () {
    $s = asmQuattro();
    $p = $s['persone'];
    asmSegna($s['assemblea'], array_values($p));
    asmPresenza($s['assemblea'], $p['Dario Dini'])->forceFill(['ora_uscita' => '19:10'])->save();

    expect(asmCostituzione($s['assemblea'])['teste_presenti'])->toBe(3);

    $punto = AssembleaPunto::create(['assemblea_id' => $s['assemblea']->id, 'numero' => 1, 'titolo' => 'Rendiconto', 'maggioranza' => 'ordinaria']);
    $this->put(route('admin.gestionale.assemblee.punti.voti', ['condominio' => $s['condominio']->id, 'assemblea' => $s['assemblea']->id, 'punto' => $punto->id]), [
        'tutti' => 'favorevole',
        'voti' => [['presenza_id' => asmPresenza($s['assemblea'], $p['Bruno Bassi'])->id, 'voto' => 'contrario']],
    ])->assertSessionHasNoErrors();

    $v = asmVotazione($punto);
    expect($v['favorevoli']['teste'])->toBe(2)
        ->and($v['contrari']['teste'])->toBe(1)
        ->and($v['intervenuti']['teste'])->toBe(3)
        ->and($v['esito_proposto'])->toBe('approvato'); // 2 su 3, 600 millesimi
});

it('avvisa se il delegato è l\'amministratore, e se un delegato supera un quinto con più di venti condòmini', function () {
    $unita = [];
    for ($i = 1; $i <= 21; $i++) {
        $unita["U{$i}"] = ['millesimi' => $i === 21 ? '40' : '48', 'titolari' => ["Condomino {$i}" => 'proprietario']];
    }
    $s = asmCondominio($unita);
    app(AventiDiritto::class)->prepara($s['assemblea']);
    $p = array_values($s['persone']);

    // Cinque deleghe a Zeta Zanetti: 5 teste su 21 sono più di un quinto (4,2).
    asmSegna($s['assemblea'], array_slice($p, 0, 5), 'delega', 'Zeta Zanetti');
    // Una delega allo studio (il nome dell'utente amministratore).
    asmSegna($s['assemblea'], [$p[6]], 'delega', 'Studio Prova');

    $assemblea = Assemblea::with(['presenze.anagrafica', 'presenze.delegato', 'punti'])->find($s['assemblea']->id);
    $avvisi = collect(app(ConteggiAssemblea::class)->avvisi($assemblea))->pluck('testo')->implode("\n");

    expect($avvisi)->toContain('Zeta Zanetti rappresenta per delega 5 condòmini')
        ->and($avvisi)->toContain('sembra essere l\'amministratore');
});

it('le unità senza titolari non fanno una testa, ma il loro valore resta nel totale', function () {
    $s = asmCondominio([
        'A' => ['millesimi' => '700', 'titolari' => ['Anna Alberti' => 'proprietario']],
        'Sfitto' => ['millesimi' => '300'],
    ]);
    $esito = app(AventiDiritto::class)->prepara($s['assemblea']);
    asmSegna($s['assemblea'], [$s['persone']['Anna Alberti']]);

    expect($esito['unita_senza_titolari'])->toHaveCount(1);
    $costituzione = asmCostituzione($s['assemblea']);
    expect($costituzione['teste_totali'])->toBe(1)
        ->and($costituzione['millesimi_presenti_testo'])->toBe('700,00')
        ->and($costituzione['valida'])->toBeTrue();
});

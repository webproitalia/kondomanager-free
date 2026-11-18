/**
 * Le scorciatoie da tastiera come si scrivono su questo computer: «⌘B» sul Mac, «Ctrl+B» altrove.
 * Le usano i suggerimenti del tasto del menu e della ricerca rapida.
 */
export const suMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform ?? '');

export const scorciatoia = (lettera: string): string => (suMac ? `⌘${lettera}` : `Ctrl+${lettera}`);

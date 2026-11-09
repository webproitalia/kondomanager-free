// I formati dell'archivio documenti (Web Pro Italia, 09/11/2026): gli stessi di App\Support\FormatiDocumento.
export const ESTENSIONI_DOCUMENTO = [
  'pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'tif', 'tiff',
  'doc', 'docx', 'odt', 'rtf', 'txt',
  'xls', 'xlsx', 'ods', 'csv',
  'xml', 'p7m', 'zip', 'eml', 'msg',
]
export const ACCETTA_DOCUMENTO = ESTENSIONI_DOCUMENTO.map((e) => '.' + e).join(',')
export const formatoDocumentoAmmesso = (file: File): boolean =>
  ESTENSIONI_DOCUMENTO.includes((file.name.split('.').pop() || '').toLowerCase())

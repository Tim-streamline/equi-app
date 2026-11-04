/** Shared identity for a browser tab's database, reset marker and attachments. */
export function browserTabId(): string {
  let id = sessionStorage.getItem('equinova:tab');
  if (!id) {
    id = crypto.randomUUID();
    sessionStorage.setItem('equinova:tab', id);
  }
  return id;
}

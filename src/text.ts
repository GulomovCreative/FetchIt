/**
 * Drop anything tag-like between < and >: messages are shown as text.
 * Entities such as &amp; are left as they are.
 */
export function stripTags (value: string = ''): string {
  return value.replace(/(<([^>]+)>)/gi, '')
}

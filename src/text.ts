/**
 * Remove HTML tags: messages are shown as text.
 */
export function stripTags (value: string = ''): string {
  return value.replace(/(<([^>]+)>)/gi, '')
}

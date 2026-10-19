// url of the backend: /<backend.dirname>/ given by the server in the page (\Core\Backend), / with the dev server
export const BASE = document.querySelector<HTMLMetaElement>('meta[name="unee-base"]')?.content || (import.meta.env.DEV ? '/' : '/backend/')

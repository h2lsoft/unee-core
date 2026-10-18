import { useRef, useState } from 'react'
import CodeMirror, { EditorView } from '@uiw/react-codemirror'
import { html } from '@codemirror/lang-html'
import { json } from '@codemirror/lang-json'
import { Box, Button, IconButton, ListSubheader, Menu, MenuItem } from '@mui/material'
import { useColorScheme } from '@mui/material/styles'
import { CloseFullscreenOutlined, OpenInFullOutlined, PostAddOutlined } from '@mui/icons-material'
import { t } from '../i18n'

const LANGUAGES = {html: () => [html()], json: () => [json()]}

export type Snippet = {label: string, group: string, content: string}

/**
 * source code editor (html, json) with line numbers and a full screen mode, snippets (ex: patterns) inserted at the cursor
 */
export default function CodeEditor({id, value, language = 'html', error = false, readOnly = false, height = 260, snippets = [], onChange}: {id?: string, value: string, language?: string, error?: boolean, readOnly?: boolean, height?: number, snippets?: Snippet[], onChange?: (value: string) => void})
{

	const [full, setFull] = useState(false)
	const [menu, setMenu] = useState<HTMLElement | null>(null)
	const view = useRef<EditorView | null>(null)
	const groups = [...new Set(snippets.map((sn) => sn.group))]

	const insert = (snippet: Snippet) => {

		setMenu(null)
		const v = view.current
		if(!v)return
		v.dispatch(v.state.replaceSelection(snippet.content))
		v.focus()

	}
	const {colorScheme} = useColorScheme()
	// long lines wrapped: html of mails is often one line per block
	const extensions = [...(LANGUAGES[language as keyof typeof LANGUAGES] ?? LANGUAGES.html)(), EditorView.lineWrapping]

	return (
		<Box sx={full
			? {position: 'fixed', inset: 0, zIndex: 1400, bgcolor: 'var(--u-paper)', display: 'flex', flexDirection: 'column'}
			: {position: 'relative', border: '1px solid', borderColor: error ? 'error.main' : 'var(--u-border-strong)', borderRadius: 2, overflow: 'hidden', bgcolor: 'var(--u-head)'}}>
			<CodeMirror
				id={id}
				value={value}
				height={full ? '100vh' : `${height}px`}
				extensions={extensions}
				theme={(colorScheme === 'dark') ? 'dark' : 'light'}
				editable={!readOnly}
				readOnly={readOnly}
				basicSetup={{foldGutter: false, highlightActiveLine: !readOnly, highlightActiveLineGutter: !readOnly}}
				onChange={onChange}
				onCreateEditor={(v) => { view.current = v }}
				style={{fontSize: 12.5}}
			/>
			{snippets.length > 0 && !readOnly && (
				<>
					<Button size="small" startIcon={<PostAddOutlined sx={{fontSize: 16}} />} onClick={(e) => setMenu(e.currentTarget)}
						sx={{position: 'absolute', top: 6, right: full ? 54 : 44, zIndex: 2, height: 30, px: 1.25, border: '1px solid var(--u-border)', borderRadius: 1.5, bgcolor: 'var(--u-paper)', color: 'var(--u-text2)', fontSize: 12.5, fontWeight: 500, '&:hover': {bgcolor: 'var(--u-hover)'}}}>{t('Insert pattern')}</Button>
					<Menu anchorEl={menu} open={!!menu} onClose={() => setMenu(null)} slotProps={{paper: {sx: {maxHeight: 360}}}} sx={{zIndex: 1500}}>
						{groups.flatMap((g) => [
							g ? <ListSubheader key={`g-${g}`} sx={{lineHeight: '30px', fontSize: 11.5, fontWeight: 600, letterSpacing: '.05em', textTransform: 'uppercase'}}>{g}</ListSubheader> : null,
							...snippets.filter((sn) => sn.group === g).map((sn, i) => <MenuItem key={`${g}-${i}`} onClick={() => insert(sn)} sx={{fontSize: 13.5}}>{sn.label}</MenuItem>),
						])}
					</Menu>
				</>
			)}
			<IconButton size="small" aria-label={full ? t('Exit full screen') : t('Full screen')} title={full ? t('Exit full screen') : t('Full screen')} onClick={() => setFull(!full)}
				sx={{position: 'absolute', top: 6, right: full ? 16 : 6, zIndex: 2, width: 30, height: 30, border: '1px solid var(--u-border)', borderRadius: 1.5, bgcolor: 'var(--u-paper)', color: 'var(--u-text3)', '&:hover': {bgcolor: 'var(--u-hover)'}}}>
				{full ? <CloseFullscreenOutlined sx={{fontSize: 16}} /> : <OpenInFullOutlined sx={{fontSize: 16}} />}
			</IconButton>
		</Box>
	)

}

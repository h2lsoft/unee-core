import { useState } from 'react'
import CodeMirror, { EditorView } from '@uiw/react-codemirror'
import { html } from '@codemirror/lang-html'
import { json } from '@codemirror/lang-json'
import { Box, IconButton } from '@mui/material'
import { useColorScheme } from '@mui/material/styles'
import { CloseFullscreenOutlined, OpenInFullOutlined } from '@mui/icons-material'
import { t } from '../i18n'

const LANGUAGES = {html: () => [html()], json: () => [json()]}

/**
 * source code editor (html, json) with line numbers and a full screen mode
 */
export default function CodeEditor({id, value, language = 'html', error = false, readOnly = false, height = 260, onChange}: {id?: string, value: string, language?: string, error?: boolean, readOnly?: boolean, height?: number, onChange?: (value: string) => void})
{

	const [full, setFull] = useState(false)
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
				style={{fontSize: 12.5}}
			/>
			<IconButton size="small" aria-label={full ? t('Exit full screen') : t('Full screen')} title={full ? t('Exit full screen') : t('Full screen')} onClick={() => setFull(!full)}
				sx={{position: 'absolute', top: 6, right: full ? 16 : 6, zIndex: 2, width: 30, height: 30, border: '1px solid var(--u-border)', borderRadius: 1.5, bgcolor: 'var(--u-paper)', color: 'var(--u-text3)', '&:hover': {bgcolor: 'var(--u-hover)'}}}>
				{full ? <CloseFullscreenOutlined sx={{fontSize: 16}} /> : <OpenInFullOutlined sx={{fontSize: 16}} />}
			</IconButton>
		</Box>
	)

}

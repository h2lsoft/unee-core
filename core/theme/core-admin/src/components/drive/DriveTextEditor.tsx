import { useEffect, useMemo, useRef, useState } from 'react'
import { Box, Button, ButtonBase, CircularProgress } from '@mui/material'
import { useColorScheme } from '@mui/material/styles'
import CodeMirror, { EditorView, keymap } from '@uiw/react-codemirror'
import { openSearchPanel } from '@codemirror/search'
import { json } from '@codemirror/lang-json'
import { html } from '@codemirror/lang-html'
import { t } from '../../i18n'
import DriveMarkdown from './DriveMarkdown'
import MarkdownToolbar, { markdownActions } from './MarkdownToolbar'
import { iniMode, markdownMode, yamlMode } from './codeModes'
import { driveApi, errorMessage, errorReason, readText, type DriveFile } from './driveApi'

/**
 * text editor of the drive (lazy chunk with codemirror): Ctrl+S saves (anywhere in the editor, toolbar included), Ctrl+F / Ctrl+H search and replace, Edit / Preview tabs for the .md
 * a file saved by someone else since the opening answers 409: overwrite or reload; json checked before the save
 */
export default function DriveTextEditor({file, text, onSaved, onDirty}: {file: DriveFile, text: string, onSaved: (file: DriveFile, copy: boolean) => void, onDirty: (dirty: boolean) => void})
{

	// result of the last save, shown in the toolbar (a toast would cover the buttons of the lightbox)
	const [note, setNote] = useState('')
	useEffect(() => {

		if(!note)return
		const timer = setTimeout(() => setNote(''), 5000)
		return () => clearTimeout(timer)

	}, [note])
	const {colorScheme} = useColorScheme()
	const [value, setValue] = useState(text)
	const [saved, setSaved] = useState(text)
	const [base, setBase] = useState(file.hash)
	const [tab, setTab] = useState<'edit' | 'preview'>('edit')
	const [busy, setBusy] = useState(false)
	const [error, setError] = useState('')
	const [conflict, setConflict] = useState(false)
	const md = (file.extension === 'md')
	const dirty = (value !== saved)

	useEffect(() => { onDirty(dirty) }, [dirty, onDirty])

	const save = async (mode: 'replace' | 'copy', force = false) => {

		if(busy)return
		setError('')

		if(file.extension === 'json' && value.trim())
		{

			try
			{

				JSON.parse(value)

			}
			catch(e)
			{

				setError(t('Invalid JSON: [ERROR]', {ERROR: (e as Error).message}))
				return

			}

		}

		setBusy(true)
		try
		{

			const r = await driveApi.saveContent(file.id, {content: value, mode, base, force})
			if(mode === 'replace')
			{

				setSaved(value)
				setBase(r.file.hash)

			}
			setConflict(false)
			setNote((mode === 'copy') ? t('Copy saved as [NAME]', {NAME: r.file.name}) : t('Saved'))
			onSaved(r.file, mode === 'copy')

		}
		catch(e)
		{

			if(errorReason(e) === 'changed')setConflict(true)
			else setError(errorMessage(e))

		}
		finally
		{

			setBusy(false)

		}

	}

	// last saved version of the server (the changes of the user are lost)
	const reload = async () => {

		setBusy(true)
		try
		{

			const r = await driveApi.file(file.id)
			const content = await readText(r.file)
			setValue(content)
			setSaved(content)
			setBase(r.file.hash)
			setConflict(false)
			setError('')

		}
		catch(e)
		{

			setError(errorMessage(e))

		}
		finally
		{

			setBusy(false)

		}

	}

	// shortcuts read the last state
	const saveRef = useRef(save)
	useEffect(() => { saveRef.current = save })

	const [view, setView] = useState<EditorView | null>(null)
	const language = {json: () => json(), xml: () => html(), md: () => markdownMode, yml: () => yamlMode, yaml: () => yamlMode, ini: () => iniMode}[file.extension as 'json']
	const extensions = useMemo(() => [
		...(language ? [language()] : []),
		EditorView.lineWrapping,
		keymap.of([
			{key: 'Mod-h', preventDefault: true, run: openSearchPanel},
			...((file.extension === 'md') ? [
				{key: 'Mod-b', preventDefault: true, run: (v: EditorView) => { markdownActions.bold(v); return true }},
				{key: 'Mod-i', preventDefault: true, run: (v: EditorView) => { markdownActions.italic(v); return true }},
				{key: 'Mod-k', preventDefault: true, run: (v: EditorView) => { markdownActions.link(v); return true }},
			] : []),
		]),
	// eslint-disable-next-line react-hooks/exhaustive-deps
	], [file.extension])

	const tabSx = (on: boolean) => ({height: 40, px: 0.5, fontSize: 13.5, fontWeight: on ? 600 : 400, borderBottom: '2px solid', borderColor: on ? 'primary.main' : 'transparent', color: on ? 'var(--u-accent-text)' : 'var(--u-text3)'})

	return (
		<Box onKeyDown={(e) => { if((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's'){ e.preventDefault(); saveRef.current('replace') } }} sx={{flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column'}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1, px: 2, minHeight: 48, borderBottom: '1px solid var(--u-border)', bgcolor: 'var(--u-toolbar)'}}>
				{md && (
					<Box role="tablist" sx={{display: 'flex', gap: 2, alignSelf: 'stretch', alignItems: 'flex-end'}}>
						<ButtonBase role="tab" aria-selected={tab === 'edit'} onClick={() => setTab('edit')} sx={tabSx(tab === 'edit')}>{t('Edit')}</ButtonBase>
						<ButtonBase role="tab" aria-selected={tab === 'preview'} onClick={() => setTab('preview')} sx={tabSx(tab === 'preview')}>{t('Preview')}</ButtonBase>
					</Box>
				)}
				<Box sx={{flex: 1, fontSize: 12.5, color: 'var(--u-muted)'}}>
					{note ? <Box component="span" role="status" sx={{fontWeight: 500, color: 'var(--u-green)'}}>{note}</Box> : (dirty ? t('Not saved') : t('All changes saved'))} - {t('Ctrl+F to search, Ctrl+H to replace')}
				</Box>
				<Button variant="outlined" color="inherit" disabled={busy} onClick={() => save('copy')} sx={{height: 32, borderColor: 'var(--u-border-strong)', color: 'var(--u-text2)', fontWeight: 500}}>{t('Save a copy')}</Button>
				<Button variant="contained" disabled={busy || !dirty} onClick={() => save('replace')} title="Ctrl+S" sx={{height: 32, minWidth: 110}}>{busy ? <CircularProgress size={18} color="inherit" /> : t('Save')}</Button>
			</Box>

			{conflict && (
				<Box role="alert" sx={{display: 'flex', alignItems: 'center', gap: 1.5, flexWrap: 'wrap', px: 2, py: 1.25, bgcolor: 'var(--u-orange-bg)', color: 'var(--u-orange)', fontSize: 13.5}}>
					<Box sx={{flex: 1, minWidth: 240}}>{t('This file was saved by someone else since you opened it.')}</Box>
					<Button size="small" variant="outlined" color="inherit" disabled={busy} onClick={reload} sx={{height: 30}}>{t('Reload (my changes are lost)')}</Button>
					<Button size="small" variant="contained" color="warning" disabled={busy} onClick={() => save('replace', true)} sx={{height: 30}}>{t('Overwrite')}</Button>
				</Box>
			)}
			{error && <Box role="alert" sx={{px: 2, py: 1.25, bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 13.5}}>{error}</Box>}

			{md && tab === 'edit' && <MarkdownToolbar view={view} />}

			<Box sx={{flex: 1, minHeight: 0, overflow: 'auto', bgcolor: 'var(--u-paper)', '& .cm-editor': {height: '100%'}, '& .cm-theme, & .cm-theme-light, & .cm-theme-dark': {height: '100%'}}}>
				{(md && tab === 'preview')
					? <DriveMarkdown text={value} />
					: <CodeMirror value={value} height="100%" autoFocus extensions={extensions} theme={(colorScheme === 'dark') ? 'dark' : 'light'}
						basicSetup={{foldGutter: false}} onChange={setValue} onCreateEditor={setView} style={{height: '100%', fontSize: 13}} />}
			</Box>
		</Box>
	)

}

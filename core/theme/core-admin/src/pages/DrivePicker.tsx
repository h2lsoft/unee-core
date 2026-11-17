import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { Box, CircularProgress } from '@mui/material'
import { t } from '../i18n'
import { useToast } from '../components/Toast'
import FileManager from '../components/drive/FileManager'
import { acceptList, driveApi, type DriveFile } from '../components/drive/driveApi'

// window that opened the picker: the opener of a popup, the parent of an iframe (same origin only)
function host(): Window | null
{

	try
	{

		if(window.opener && !window.opener.closed && window.opener.location.origin === window.location.origin)return window.opener as Window
		if(window.parent !== window && window.parent.location.origin === window.location.origin)return window.parent

	}
	catch
	{

		// other origin: no access

	}

	return null

}

// input of the host that receives the value (target=<id>)
function hostInput(target: string): HTMLInputElement | HTMLTextAreaElement | null
{

	const win = host()
	if(!win || !target)return null
	const el = win.document.getElementById(target)
	return (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) ? el as HTMLInputElement : null

}

/**
 * picker mode of the File Manager (/backend/drive-picker/), opened in an iframe or a popup, no layout
 * parameters: path (folder opened), root (navigation locked under it), return (absolute = url without domain, public = complete url, path = path in the drive),
 * target (id of the input of the host that receives the value, input and change events dispatched), field (name sent back), multiple (value = json array),
 * accept (pdf,jpg or image, video, audio, document), upload (import allowed), view (list, grid), close (0 = stays open)
 * the host always receives a postMessage {type: 'unee.files.select', field, value, files}, then {type: 'unee.files.close', field} unless close=0
 */
export default function DrivePicker()
{

	const [params] = useSearchParams()
	const toast = useToast()
	const [ready, setReady] = useState<{start: number, root: number | null, preselect: number} | null>(null)

	const target = params.get('target') ?? ''
	const field = params.get('field') ?? target
	const ret = params.get('return') ?? 'absolute'
	const multiple = params.get('multiple') === '1'
	const view = params.get('view')

	useEffect(() => {

		document.title = t('Choose a file')

		// root, folder to open and current value of the field, resolved in the drive
		const locate = (value: string) => value ? driveApi.locate(value).catch(() => null) : Promise.resolve(null)
		const current = hostInput(target)?.value ?? ''
		const first = multiple ? ((): string => { try { return String(JSON.parse(current)[0] ?? '') } catch { return '' } })() : current

		Promise.all([locate(params.get('root') ?? ''), locate(params.get('path') ?? ''), locate(first)]).then(([root, path, value]) => {

			if(params.get('root') && !root)toast(t('Folder not found: [PATH]', {PATH: params.get('root') ?? ''}), 'warning')
			if(params.get('path') && !path)toast(t('Folder not found: [PATH]', {PATH: params.get('path') ?? ''}), 'warning')

			const root_id = root ? root.folder : null
			setReady({start: value?.folder ?? path?.folder ?? root_id ?? 0, root: root_id, preselect: value?.file ?? 0})

		})

	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [])

	const close = () => {

		const win = host()
		if(params.get('close') === '0')return
		if(window.opener)window.close()
		else win?.postMessage({type: 'unee.files.close', field}, window.location.origin)

	}

	const pick = (files: DriveFile[]) => {

		const values = files.map((f) => {

			if(ret === 'public')return `${window.location.origin}${f.url}`
			if(ret === 'path')return `${(f.location ?? '').replace(/\/$/, '')}/${f.name}`
			return f.url

		})

		const value = multiple ? JSON.stringify(values) : (values[0] ?? '')

		// input of the host: value set the way react sees it (native setter + events)
		const input = hostInput(target)
		if(input)
		{

			const win = host() as Window & typeof globalThis
			const proto = (input.tagName === 'TEXTAREA') ? win.HTMLTextAreaElement.prototype : win.HTMLInputElement.prototype
			Object.getOwnPropertyDescriptor(proto, 'value')?.set?.call(input, value)
			input.dispatchEvent(new win.Event('input', {bubbles: true}))
			input.dispatchEvent(new win.Event('change', {bubbles: true}))

		}

		host()?.postMessage({type: 'unee.files.select', field, value, files: files.map((f, i) => ({id: f.id, name: f.name, path: `${(f.location ?? '').replace(/\/$/, '')}/${f.name}`, url: values[i], size: f.size, mime: f.mime}))}, window.location.origin)
		close()

	}

	const cancel = () => {

		if(window.opener)window.close()
		else host()?.postMessage({type: 'unee.files.close', field}, window.location.origin)

	}

	if(!ready)return <Box sx={{display: 'flex', justifyContent: 'center', mt: 10}}><CircularProgress /></Box>

	return (
		<Box sx={{height: '100vh'}}>
			<FileManager start={ready.start} forcedView={(view === 'grid' || view === 'list') ? view : null}
				picker={{root: ready.root, accept: acceptList(params.get('accept') ?? ''), multiple, upload: params.get('upload') === '1', preselect: ready.preselect, onPick: pick, onCancel: cancel}} />
		</Box>
	)

}

import { useCallback, useEffect, useState } from 'react'
import { Box, ButtonBase, CircularProgress, IconButton, Tooltip } from '@mui/material'
import { BlockOutlined, ContentCopyOutlined, DeleteOutlineOutlined, EditOutlined, LockOutlined, NotificationsActiveOutlined } from '@mui/icons-material'
import { t } from '../../i18n'
import { useToast } from '../Toast'
import UserAvatar from '../UserAvatar'
import { FileIcon } from '../FileInput'
import ShareDialog, { copyText, LinkState } from './ShareDialog'
import { driveApi, driveDate, driveDay, errorMessage, type DriveLink } from './driveApi'

const COLUMNS = 'minmax(220px, 1fr) 96px 110px 100px 140px 44px 112px'

/**
 * Public links of the sidebar: share links of the user (all of them with the manage right), state, downloads, expiration, last download; copy, edit, revoke, delete
 * a click on the name of the file opens its folder
 */
export default function DriveLinks({onFolder, onCount}: {onFolder: (folder: number) => void, onCount?: (count: number) => void})
{

	const toast = useToast()
	const [links, setLinks] = useState<DriveLink[] | null>(null)
	const [editing, setEditing] = useState<DriveLink | null>(null)

	const load = useCallback(() => {

		driveApi.links().then((r) => { setLinks(r.links); onCount?.(r.links.length) }).catch((e) => { setLinks([]); onCount?.(0); toast(errorMessage(e), 'error') })

	}, [toast, onCount])

	useEffect(load, [load])

	const act = async (run: () => Promise<unknown>, message: string) => {

		try
		{

			await run()
			toast(message)
			load()

		}
		catch(e)
		{

			toast(errorMessage(e), 'error')

		}

	}

	if(links === null)return <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress size={26} /></Box>
	if(!links.length)return <Box sx={{py: 8, px: 2, textAlign: 'center', color: 'var(--u-muted)', fontSize: 13.5}}>{t('No share link: right click on a file > Share by link...')}</Box>

	const head = {fontSize: 12, fontWeight: 600, color: 'var(--u-muted)'}

	return (
		<Box sx={{p: '8px 10px', minWidth: 950}}>
			<Box sx={{display: 'grid', gridTemplateColumns: COLUMNS, gap: 1.5, height: 34, alignItems: 'center', px: 1.75, borderBottom: '1px solid var(--u-border-soft)', mb: 0.5}}>
				<Box sx={head}>{t('File')}</Box>
				<Box sx={head}>{t('State')}</Box>
				<Box sx={{...head, textAlign: 'right'}}>{t('Downloads')}</Box>
				<Box sx={{...head, textAlign: 'right'}}>{t('Expires on')}</Box>
				<Box sx={{...head, textAlign: 'right'}}>{t('Last download')}</Box>
				<Box sx={{...head, textAlign: 'center'}}>{t('By')}</Box>
				<Box />
			</Box>
			{links.map((l) => (
				<Box key={l.id} sx={{display: 'grid', gridTemplateColumns: COLUMNS, gap: 1.5, alignItems: 'center', minHeight: 44, px: 1.75, borderRadius: 1.5, fontSize: 13.5, '&:hover': {bgcolor: 'var(--u-hover)'}}}>
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, minWidth: 0}}>
						<FileIcon name={l.file?.name ?? 'file'} size={22} />
						<Box sx={{minWidth: 0}}>
							<ButtonBase disabled={!l.file || l.file.trashed} onClick={() => l.file && onFolder(l.file.folder)} title={t('Open the folder')}
								sx={{display: 'block', maxWidth: '100%', textAlign: 'left', fontSize: 13.5, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', '&:hover': {textDecoration: 'underline'}}}>{l.file?.name ?? t('Deleted file')}</ButtonBase>
							<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75, fontSize: 11.5, color: 'var(--u-muted)', minWidth: 0}}>
								{l.note && <Box component="span" title={l.note} sx={{flexShrink: 0, maxWidth: '50%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontWeight: 600, color: 'var(--u-text2)'}}>{l.note}</Box>}
								<Box component="span" sx={{overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{l.file?.trashed ? t('In the trash') : (l.file?.location ?? '')}</Box>
								{l.password && <Tooltip title={t('Password')}><LockOutlined sx={{fontSize: 13}} /></Tooltip>}
								{l.notify && <Tooltip title={t('Notified at each download')}><NotificationsActiveOutlined sx={{fontSize: 13}} /></Tooltip>}
							</Box>
						</Box>
					</Box>
					<Box><LinkState state={l.state} /></Box>
					<Box sx={{textAlign: 'right', color: 'var(--u-text3)', fontVariantNumeric: 'tabular-nums'}}>{l.max_downloads ? `${l.downloads} / ${l.max_downloads}` : l.downloads}</Box>
					<Box sx={{textAlign: 'right', color: 'var(--u-text3)'}}>{l.expires_at ? driveDay(l.expires_at) : t('Never')}</Box>
					<Box sx={{textAlign: 'right', color: 'var(--u-text3)', fontSize: 13}}>{l.last_download_at ? driveDate(l.last_download_at) : '-'}</Box>
					<Box sx={{display: 'flex', justifyContent: 'center'}}>{l.by ? <UserAvatar avatar={l.by.avatar} title={l.by.name} size={26} /> : '-'}</Box>
					<Box sx={{display: 'flex', justifyContent: 'flex-end'}}>
						<Tooltip title={t('Copy the link')}><span><IconButton size="small" aria-label={t('Copy the link')} disabled={l.state !== 'ok'} onClick={async () => { const ok = await copyText(l.url); toast(ok ? t('Link copied') : t('The link cannot be copied'), ok ? 'success' : 'error') }}><ContentCopyOutlined sx={{fontSize: 18}} /></IconButton></span></Tooltip>
						<Tooltip title={t('Edit')}><span><IconButton size="small" aria-label={t('Edit')} disabled={!l.file || l.state === 'revoked' || l.state === 'gone'} onClick={() => setEditing(l)}><EditOutlined sx={{fontSize: 18}} /></IconButton></span></Tooltip>
						{l.state !== 'revoked'
							? <Tooltip title={t('Revoke')}><IconButton size="small" aria-label={t('Revoke')} onClick={() => act(() => driveApi.revokeLink(l.id), t('Link revoked'))}><BlockOutlined sx={{fontSize: 18}} /></IconButton></Tooltip>
							: <Tooltip title={t('Delete')}><IconButton size="small" aria-label={t('Delete')} onClick={() => act(() => driveApi.deleteLink(l.id), t('Link deleted'))}><DeleteOutlineOutlined sx={{fontSize: 18}} /></IconButton></Tooltip>}
					</Box>
				</Box>
			))}
			{editing?.file && <ShareDialog file={{id: editing.file.id, name: editing.file.name}} edit={editing} onClose={() => { setEditing(null); load() }} onChanged={load} />}
		</Box>
	)

}

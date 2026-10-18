import { useEffect, useState } from 'react'
import { Box, Button, ButtonBase, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, Typography, useMediaQuery, useTheme } from '@mui/material'
import { CloseOutlined } from '@mui/icons-material'
import { api, ApiException, type Version } from '../api'
import { getLanguage, t } from '../i18n'
import { useToast } from './Toast'

/**
 * versions of a record (plugin with versioning): list on the left, changes of the selected version on the right, restore
 */
export default function HistoryDialog({module, id, title, onClose, onRestored}: {module: string, id: number, title: string, onClose: () => void, onRestored: () => void})
{

	const theme = useTheme()
	const full_screen = useMediaQuery(theme.breakpoints.down('md'))
	const toast = useToast()
	const [versions, setVersions] = useState<Version[] | null>(null)
	const [selected, setSelected] = useState(0)
	const [restoring, setRestoring] = useState(false)

	useEffect(() => {

		api<{versions: Version[]}>(`${module}/${id}/versions/`).then((d) => setVersions(d.versions)).catch(() => setVersions([]))

	}, [module, id])

	const version = versions?.[selected]
	const format = (date: string) => new Intl.DateTimeFormat(getLanguage(), {dateStyle: 'medium', timeStyle: 'short', hourCycle: 'h23'}).format(new Date(date))

	const restore = async () => {

		if(!version)return
		setRestoring(true)

		try
		{

			const data = await api<{message: string}>(`${module}/${id}/versions/${version.id}/restore/`, {method: 'POST'})
			toast(data.message)
			onRestored()

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')
			setRestoring(false)

		}

	}

	return (
		<Dialog open onClose={(_, reason) => reason !== 'backdropClick' && onClose()} fullScreen={full_screen} maxWidth={false} slotProps={{paper: {sx: {width: {md: 920}, maxWidth: '100%'}}}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', pr: 1.5, fontSize: 18, fontWeight: 600}}>
				<Box component="span" sx={{flexGrow: 1}}>{title}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>
			<DialogContent sx={{pt: 0.5}}>
				{!versions && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress /></Box>}
				{versions?.length === 0 && <Typography sx={{py: 4, textAlign: 'center', color: 'var(--u-muted)'}}>{t('No version yet')}</Typography>}
				{!!versions?.length && (
					<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', md: '230px 1fr'}, border: '1px solid var(--u-border)', borderRadius: '6px', overflow: 'hidden', minHeight: 330}}>
						<Box role="listbox" aria-label={t('Versions')} sx={{borderRight: {md: '1px solid var(--u-border)'}, borderBottom: {xs: '1px solid var(--u-border)', md: 0}, bgcolor: 'var(--u-head)', overflowY: 'auto', maxHeight: {xs: 200, md: 420}}}>
							{versions.map((v, i) => (
								<ButtonBase key={v.id} role="option" aria-selected={i === selected} onClick={() => setSelected(i)} sx={{width: '100%', display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 0.25, px: 1.5, py: 1.1, borderLeft: '3px solid', borderColor: i === selected ? 'primary.main' : 'transparent', bgcolor: i === selected ? 'var(--u-accent-bg)' : 'transparent', textAlign: 'left'}}>
									<Box component="span" sx={{fontSize: 13.5, fontWeight: i === selected ? 600 : 500, color: 'var(--u-text)'}}>{format(v.date)}</Box>
									<Box component="span" sx={{fontSize: 12, color: 'var(--u-muted)'}}>{v.author} - {v.created ? t('first version') : t('[COUNT] field(s)', {COUNT: v.changes.length})}</Box>
								</ButtonBase>
							))}
						</Box>
						{version && (
							<Box sx={{p: 1.75, minWidth: 0, overflowX: 'auto'}}>
								<Typography sx={{fontSize: 14, fontWeight: 600, mb: 1.25}}>{t('Changes of [DATE] by [AUTHOR]', {DATE: format(version.date), AUTHOR: version.author})}</Typography>
								<Box component="table" sx={{width: '100%', borderCollapse: 'collapse', fontSize: 13, '& th': {textAlign: 'left', px: 1.25, height: 32, fontSize: 11, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--u-text3)', bgcolor: 'var(--u-head)', borderBottom: '1px solid var(--u-border)'}, '& td': {px: 1.25, py: 1, borderBottom: '1px solid var(--u-border-soft)', verticalAlign: 'top'}}}>
									<thead><tr><th style={{width: 140}}>{t('Field')}</th>{!version.created && <th>{t('Before')}</th>}<th>{version.created ? t('Value') : t('After')}</th></tr></thead>
									<tbody>
										{version.changes.map((c) => (
											<tr key={c.field}>
												<td style={{fontWeight: 500, color: 'var(--u-text2)'}}>{c.label}</td>
												{!version.created && <td><Box component="span" sx={{px: 0.5, borderRadius: '3px', bgcolor: 'var(--u-red-bg)', color: 'var(--u-red)', textDecoration: 'line-through', overflowWrap: 'anywhere'}}>{c.before || t('(empty)')}</Box></td>}
												<td><Box component="span" sx={{px: 0.5, borderRadius: '3px', bgcolor: 'var(--u-green-bg)', color: 'var(--u-green)', overflowWrap: 'anywhere'}}>{c.after || t('(empty)')}</Box></td>
											</tr>
										))}
									</tbody>
								</Box>
								<Typography sx={{mt: 1.5, fontSize: 12, color: 'var(--u-muted)'}}>{t('Restore puts the values of this version back in the record (a new version is saved).')}</Typography>
							</Box>
						)}
					</Box>
				)}
			</DialogContent>
			<DialogActions sx={{px: 3, pt: 1, pb: 2.5}}>
				<Button variant="contained" disabled={!version || selected === 0 || restoring} onClick={restore}>{restoring ? <CircularProgress size={20} color="inherit" /> : t('Restore this version')}</Button>
			</DialogActions>
		</Dialog>
	)

}

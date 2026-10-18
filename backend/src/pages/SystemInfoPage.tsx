import { useEffect, useState } from 'react'
import { Box, CircularProgress, InputBase, LinearProgress, Tab, Tabs, Typography } from '@mui/material'
import { SearchOutlined } from '@mui/icons-material'
import { api, ApiException } from '../api'
import { t } from '../i18n'
import BookmarkButton from '../components/BookmarkButton'
import { MenuIcon } from '../components/icons'

type Pair = {name: string, value: string}
type Stat = {label: string, value: string, icon: string, percent?: number}
type SystemInfo = {
	title: string
	server: {stats: Stat[], variables: Pair[]}
	php: {stats: Stat[], ini: Pair[], extensions: string[], env: Pair[]}
	database: {version: string, variables: Pair[]}
}

const BORDER = '#e6e8ee'

// usage colors: green, orange from 70%, red from 90%
const usageColor = (percent: number) => (percent >= 90) ? '#d32f2f' : ((percent >= 70) ? '#ed8b00' : '#0f9d75')

// figure with its icon, a bar for a usage (memory, swap, disk)
function StatCard({stat}: {stat: Stat})
{

	const color = (stat.percent !== undefined) ? usageColor(stat.percent) : ''

	return (
		<Box title={stat.value} sx={{height: 104, boxSizing: 'border-box', display: 'flex', gap: 1.5, alignItems: 'flex-start', p: 1.75, bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '8px', minWidth: 0, transition: 'border-color .15s, box-shadow .15s', '&:hover': {borderColor: '#c9bdff', boxShadow: '0 4px 14px rgba(16, 20, 40, 0.06)'}}}>
			<Box sx={{width: 40, height: 40, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: '10px', bgcolor: color ? `${color}1a` : '#f0ecff', color: color || 'primary.main'}}><MenuIcon name={stat.icon} fontSize={22} /></Box>
			<Box sx={{flexGrow: 1, minWidth: 0}}>
				<Box sx={{display: 'flex', alignItems: 'baseline', gap: 1}}>
					<Typography sx={{flexGrow: 1, fontSize: 12, fontWeight: 500, letterSpacing: '.04em', textTransform: 'uppercase', color: '#6b7088'}}>{stat.label}</Typography>
					{stat.percent !== undefined && <Typography sx={{fontSize: 13, fontWeight: 600, color}}>{stat.percent}%</Typography>}
				</Box>
				{/* same height for every card: two lines at most, the full value in the tooltip */}
				<Typography sx={{mt: 0.25, fontSize: 15, fontWeight: 600, lineHeight: 1.35, overflowWrap: 'anywhere', display: '-webkit-box', WebkitLineClamp: (stat.percent !== undefined) ? 1 : 2, WebkitBoxOrient: 'vertical', overflow: 'hidden'}}>{stat.value}</Typography>
				{stat.percent !== undefined && <LinearProgress variant="determinate" value={Math.min(100, stat.percent)} sx={{mt: 1, height: 6, borderRadius: 3, bgcolor: '#eef0f5', '& .MuiLinearProgress-bar': {bgcolor: color, borderRadius: 3}}} />}
			</Box>
		</Box>
	)

}

const stat_grid = {display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 1.25}

// section with a name / value table, rows filtered by the search
function PairTable({title, pairs, filter}: {title: string, pairs: Pair[], filter: string})
{

	const rows = filter ? pairs.filter((p) => `${p.name} ${p.value}`.toLowerCase().includes(filter)) : pairs
	if(!rows.length)return null

	return (
		<Box component="section" sx={{bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '6px', overflow: 'hidden'}}>
			<Typography component="h2" sx={{display: 'flex', alignItems: 'center', height: 38, px: 1.75, borderBottom: `1px solid ${BORDER}`, fontSize: 12.5, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: '#3a3f55'}}>{title}</Typography>
			<Box component="table" sx={{width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed'}}>
				<tbody>
					{rows.map((p) => (
						<Box component="tr" key={p.name} sx={{'&:hover': {bgcolor: '#f8f9fb'}}}>
							<Box component="td" sx={{width: {xs: '40%', md: 300}, px: 1.75, py: 0.75, borderBottom: '1px solid #eef0f4', fontFamily: 'ui-monospace, Menlo, Consolas, monospace', fontSize: 12.5, color: '#3a3f55', overflowWrap: 'anywhere', verticalAlign: 'top'}}>{p.name}</Box>
							<Box component="td" sx={{px: 1.75, py: 0.75, borderBottom: '1px solid #eef0f4', fontSize: 13, overflowWrap: 'anywhere', color: p.value === '********' ? '#9a9fb3' : (p.value === '' ? '#9a9fb3' : '#1f2335')}}>{p.value === '' ? '-' : p.value}</Box>
						</Box>
					))}
				</tbody>
			</Box>
		</Box>
	)

}

/**
 * module system-info: php, server and database information, secrets masked by the api
 */
export default function SystemInfoPage({icon}: {icon: string})
{

	const [data, setData] = useState<SystemInfo | null>(null)
	const [error, setError] = useState('')
	const [tab, setTab] = useState('server')
	const [search, setSearch] = useState('')

	useEffect(() => {

		api<SystemInfo>('system-info/').then(setData).catch((e) => setError(e instanceof ApiException ? e.error.message : t('network error, please retry')))

	}, [])

	if(!data)return <Box sx={{display: 'flex', justifyContent: 'center', py: 8}}>{error ? <Typography color="error">{error}</Typography> : <CircularProgress />}</Box>

	const filter = search.trim().toLowerCase()
	const extensions = filter ? data.php.extensions.filter((e) => e.toLowerCase().includes(filter)) : data.php.extensions

	return (
		<>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25}}>
				<Box sx={{width: 36, height: 36, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: 2, bgcolor: '#f0ecff', color: 'primary.main'}}><MenuIcon name={icon} /></Box>
				<Typography component="h1" sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em'}}>{data.title}</Typography>
				<BookmarkButton module="system-info" />
			</Box>

			<Tabs value={tab} onChange={(_, value) => setTab(value)} sx={{minHeight: 40, mt: -1, borderBottom: `1px solid ${BORDER}`, '& .MuiTab-root': {minHeight: 40, px: 1.75, textTransform: 'uppercase', fontSize: 13, fontWeight: 600, letterSpacing: '.04em'}}}>
				<Tab value="server" label={t('Server')} />
				<Tab value="php" label="PHP" />
				<Tab value="database" label={t('Database')} />
			</Tabs>

			{tab === 'server' && <Box sx={stat_grid}>{data.server.stats.map((s) => <StatCard key={s.label} stat={s} />)}</Box>}
			{tab === 'php' && <Box sx={stat_grid}>{data.php.stats.map((s) => <StatCard key={s.label} stat={s} />)}</Box>}
			{tab === 'database' && <Box sx={stat_grid}><StatCard stat={{label: t('Version'), value: data.database.version, icon: 'database'}} /></Box>}

			<Box sx={{display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 1.5}}>
				<Box component="label" sx={{width: {xs: '100%', sm: 300}, height: 38, display: 'flex', alignItems: 'center', gap: 1, px: 1.5, border: '1px solid #d5d8e3', borderRadius: 2, bgcolor: '#fff', color: '#6b7088'}}>
					<SearchOutlined sx={{fontSize: 18}} />
					<InputBase type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Filter: name or value...')} inputProps={{'aria-label': t('Filter')}} sx={{flexGrow: 1, fontSize: 14}} />
				</Box>
				<Typography sx={{fontSize: 13, color: '#6b7088'}}>{t('Values whose name holds password, secret, token or key are masked.')}</Typography>
			</Box>

			{tab === 'php' && (
				<>
					<PairTable title={t('Settings (php.ini)')} pairs={data.php.ini} filter={filter} />
					{extensions.length > 0 && (
						<Box component="section" sx={{bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '6px', overflow: 'hidden'}}>
							<Typography component="h2" sx={{display: 'flex', alignItems: 'center', height: 38, px: 1.75, borderBottom: `1px solid ${BORDER}`, fontSize: 12.5, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: '#3a3f55'}}>{t('Extensions')}</Typography>
							<Box sx={{display: 'flex', flexWrap: 'wrap', gap: 0.75, p: 1.5}}>
								{extensions.map((e) => <Box component="span" key={e} sx={{px: 1, py: '1px', borderRadius: 2.5, bgcolor: '#eef0f5', fontSize: 12, color: '#3a3f55'}}>{e}</Box>)}
							</Box>
						</Box>
					)}
					<PairTable title={t('Environment')} pairs={data.php.env} filter={filter} />
				</>
			)}
			{tab === 'server' && <PairTable title={t('Variables')} pairs={data.server.variables} filter={filter} />}
			{tab === 'database' && <PairTable title={t('Variables')} pairs={data.database.variables} filter={filter} />}
		</>
	)

}

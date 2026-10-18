import { getLanguage, t } from '../i18n'

/**
 * relative time of a date (iso 8601 or Y-m-d H:i:s), ex: "just now", "15 hours ago", "in 2 days"
 */
export function relativeTime(value: string, now: number = Date.now()): string
{

	const date = new Date(value.includes('T') ? value : value.replace(' ', 'T'))
	if(isNaN(date.getTime()))return value

	const seconds = Math.round((date.getTime() - now) / 1000)
	if(Math.abs(seconds) < 60)return t('just now')

	const units: [Intl.RelativeTimeFormatUnit, number][] = [['minute', 60], ['hour', 3600], ['day', 86400], ['month', 2592000], ['year', 31536000]]
	let unit: Intl.RelativeTimeFormatUnit = 'minute'
	let divisor = 60
	for(const [u, d] of units)if(Math.abs(seconds) >= d){ unit = u; divisor = d }

	return new Intl.RelativeTimeFormat(getLanguage(), {numeric: 'auto'}).format(Math.round(seconds / divisor), unit)

}

/**
 * relative day of a date (Y-m-d, or iso 8601 read in local time): today, tomorrow, yesterday, otherwise a short date
 */
export function relativeDay(value: string, now: Date = new Date()): string
{

	// a full date (iso with offset) is taken in the local time of the browser
	const full = value.includes('T') ? new Date(value) : null
	const [y, m, d] = (full && !isNaN(full.getTime())) ? [full.getFullYear(), full.getMonth() + 1, full.getDate()] : value.slice(0, 10).split('-').map(Number)
	if(!y || !m || !d)return value

	const date = new Date(y, m - 1, d)
	const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
	const days = Math.round((date.getTime() - today.getTime()) / 86400000)
	if(Math.abs(days) <= 1)return new Intl.RelativeTimeFormat(getLanguage(), {numeric: 'auto'}).format(days, 'day')

	return new Intl.DateTimeFormat(getLanguage(), {day: 'numeric', month: 'short', ...(date.getFullYear() !== now.getFullYear() ? {year: 'numeric'} : {})}).format(date)

}

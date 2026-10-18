import { Avatar, Tooltip } from '@mui/material'

export type AvatarData = {initials: string, color: string, url?: string}

/**
 * small avatar of a user: picture when he has one, otherwise his initials on his color
 */
export default function UserAvatar({avatar, title = '', size = 28}: {avatar: AvatarData, title?: string, size?: number})
{

	const node = (
		<Avatar src={avatar.url || undefined} alt={title} sx={{width: size, height: size, flexShrink: 0, bgcolor: avatar.color, color: '#fff', fontSize: Math.round(size * 0.4), fontWeight: 600, boxShadow: size <= 18 ? '0 0 0 1px var(--u-paper)' : 'none'}}>
			{avatar.initials}
		</Avatar>
	)

	return title ? <Tooltip title={title}>{node}</Tooltip> : node

}

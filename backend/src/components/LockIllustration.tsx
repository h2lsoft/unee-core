// default image of the login page: a lock with the colors of the unee logo
export default function LockIllustration()
{

	return (
		<svg viewBox="0 0 200 240" width="200" height="240" role="img" aria-label="lock">
			<defs>
				<linearGradient id="unee-lock" x1="0" y1="0" x2="1" y2="1">
					<stop offset="0" stopColor="#1a8cff" />
					<stop offset="1" stopColor="#d01bff" />
				</linearGradient>
			</defs>
			<path d="M50 105V75a50 50 0 0 1 100 0v30" fill="none" stroke="url(#unee-lock)" strokeWidth="18" strokeLinecap="round" />
			<rect x="20" y="100" width="160" height="125" rx="22" fill="url(#unee-lock)" />
			<circle cx="100" cy="150" r="15" fill="#fff" />
			<rect x="93" y="155" width="14" height="35" rx="7" fill="#fff" />
		</svg>
	)

}

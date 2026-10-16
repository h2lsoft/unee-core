import { AppBar, Box, Button, Toolbar, Typography } from '@mui/material'
import { useAuth } from '../auth'

export default function Dashboard()
{

	const {user, logout} = useAuth()

	return (
		<Box>
			<AppBar position="static">
				<Toolbar>
					<Typography variant="h6" sx={{flexGrow: 1}}>Unee</Typography>
					<Typography sx={{mr: 2}}>{user?.firstname} {user?.lastname}</Typography>
					<Button color="inherit" onClick={logout}>Logout</Button>
				</Toolbar>
			</AppBar>
			<Box sx={{p: 3}}>
				<Typography variant="h4" component="h1">Dashboard</Typography>
			</Box>
		</Box>
	)

}

import type { SvgIconComponent } from '@mui/icons-material'
import {
	ArticleOutlined, BadgeOutlined, BarChartOutlined, BookmarkBorderOutlined, CampaignOutlined, DashboardOutlined, DescriptionOutlined,
	EventOutlined, ExtensionOutlined, FolderOutlined, GroupOutlined, HistoryOutlined, ImageOutlined, Inventory2Outlined, LinkOutlined,
	ListOutlined, LockOutlined, MailOutlined, PeopleOutlined, ReceiptOutlined, ScheduleOutlined, SettingsOutlined, ShoppingCartOutlined,
	SpeedOutlined, StickyNote2Outlined, StorageOutlined, SupportAgentOutlined, TranslateOutlined, TuneOutlined, WebOutlined,
} from '@mui/icons-material'

// icon names usable in xcore_menu.icon and xcore_plugin.icon (material symbols names), unknown = extension
const ICONS: Record<string, SvgIconComponent> = {
	article: ArticleOutlined,
	badge: BadgeOutlined,
	bar_chart: BarChartOutlined,
	bookmark: BookmarkBorderOutlined,
	campaign: CampaignOutlined,
	dashboard: DashboardOutlined,
	description: DescriptionOutlined,
	event: EventOutlined,
	extension: ExtensionOutlined,
	folder: FolderOutlined,
	group: GroupOutlined,
	history: HistoryOutlined,
	image: ImageOutlined,
	inventory: Inventory2Outlined,
	link: LinkOutlined,
	list: ListOutlined,
	lock: LockOutlined,
	mail: MailOutlined,
	people: PeopleOutlined,
	receipt: ReceiptOutlined,
	schedule: ScheduleOutlined,
	settings: SettingsOutlined,
	shopping_cart: ShoppingCartOutlined,
	speed: SpeedOutlined,
	note: StickyNote2Outlined,
	storage: StorageOutlined,
	support: SupportAgentOutlined,
	translate: TranslateOutlined,
	tune: TuneOutlined,
	web: WebOutlined,
}

export function MenuIcon({name, fontSize = 20}: {name: string, fontSize?: number})
{

	const Icon = ICONS[name] ?? ExtensionOutlined
	return <Icon sx={{fontSize}} />

}

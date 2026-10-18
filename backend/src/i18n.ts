// interface translations, key = english text, [KEY] replaced by params
// language: the user's one once logged, the browser's one before (labels coming from the api are translated by php)

const fr: Record<string, string> = {
	// auth pages
	'Sign in': 'Connexion',
	'Sign In': 'Connexion',
	'Enter your credentials to access the back office.': 'Saisissez vos identifiants pour accéder au back-office.',
	'Email address': 'Adresse e-mail',
	'Login': 'Identifiant',
	'Password': 'Mot de passe',
	'Forgot password?': 'Mot de passe oublié ?',
	'Forgot password': 'Mot de passe oublié',
	'Forgot Password': 'Mot de passe oublié',
	'Enter your email address and we will send you a recovery link.': 'Saisissez votre adresse e-mail, nous vous enverrons un lien de récupération.',
	'Send me recovery link': 'Envoyer le lien de récupération',
	'Back to sign in': 'Retour à la connexion',
	'Reset password': 'Réinitialiser le mot de passe',
	'Reset Password': 'Réinitialisation du mot de passe',
	'Choose a new password for your account.': 'Choisissez un nouveau mot de passe pour votre compte.',
	'New password': 'Nouveau mot de passe',
	'Confirm password': 'Confirmer le mot de passe',
	'This link is invalid or has expired, ask for a new one.': 'Ce lien est invalide ou a expiré, demandez-en un nouveau.',
	'Welcome to Unee': 'Bienvenue sur Unee',
	'Sign in to manage your application.': 'Connectez-vous pour gérer votre application.',
	'show password': 'afficher le mot de passe',
	'hide password': 'masquer le mot de passe',
	'network error, please retry': 'erreur réseau, veuillez réessayer',

	// layout
	'Dashboard': 'Tableau de bord',
	'Collapse menu': 'Réduire le menu',
	'Expand menu': 'Déplier le menu',
	'Open menu': 'Ouvrir le menu',
	'Main menu': 'Menu principal',
	'User menu': 'Menu utilisateur',
	'Language': 'Langue',
	'Calendar': 'Calendrier',
	'Bookmarks': 'Favoris',
	'Sign out': 'Se déconnecter',
	'Page not found': 'Page introuvable',
	'This page does not exist or you do not have access to it.': "Cette page n'existe pas ou vous n'y avez pas accès.",
	'Back to dashboard': 'Retour au tableau de bord',
	'Welcome, [NAME]': 'Bienvenue, [NAME]',
	'Your modules are in the menu.': 'Vos modules sont dans le menu.',

	// grid
	'Add': 'Ajouter',
	'Edit': 'Modifier',
	'Delete': 'Supprimer',
	'More actions': "Plus d'actions",
	'All': 'Tous',
	'Search': 'Rechercher',
	'Filters': 'Filtres',
	'Rows per page': 'Lignes par page',
	'[FROM] to [TO] (total [TOTAL])': 'De [FROM] à [TO] (total [TOTAL])',
	'Page': 'Page',
	'Page number': 'Numéro de page',
	'Bookmark': 'Favori',
	'Grid menu': 'Menu de la grille',
	'Saved filters': 'Filtres enregistrés',
	'No saved filter': 'Aucun filtre enregistré',
	'Columns': 'Colonnes',
	'Advanced filters': 'Filtres avancés',
	'Match': 'Correspondre à',
	'all conditions': 'toutes les conditions',
	'any condition': 'au moins une condition',
	'Add condition': 'Ajouter une condition',
	'Remove condition': 'Retirer la condition',
	'Column': 'Colonne',
	'Operator': 'Opérateur',
	'Value': 'Valeur',
	'Save filter': 'Enregistrer le filtre',
	'Filter name': 'Nom du filtre',
	'Share': 'Partager',
	'Shared with my group': 'Partagé avec mon groupe',
	'Filter saved': 'Filtre enregistré',
	'Reset': 'Réinitialiser',
	'Select page': 'Sélectionner la page',
	'Clear selection': 'Vider la sélection',
	'Mass edit': 'Édition en masse',
	'Check / uncheck all': 'Cocher / décocher tout',
	'Order': 'Ordre',
	'Drag to reorder': 'Glisser pour réordonner',
	'Browse icons': 'Parcourir les icônes',
	'Check the fields to change, the others stay unchanged.': 'Cochez les champs à modifier, les autres restent inchangés.',
	'Change': 'Modifier',
	'Apply to [COUNT] records': 'Appliquer à [COUNT] enregistrements',
	'[COUNT] updated': '[COUNT] modifié(s)',
	'Done': 'Terminé',
	'Selection menu': 'Menu de sélection',
	'[COUNT] selected': '[COUNT] sélectionné(s)',
	'Delete selection': 'Supprimer la sélection',
	'Select all': 'Tout sélectionner',
	'Select row': 'Sélectionner la ligne',
	'Previous page': 'Page précédente',
	'Next page': 'Page suivante',
	'No results': 'Aucun résultat',
	'Yes': 'Oui',
	'No': 'Non',
	'Actions': 'Actions',
	'contains': 'contient',
	'equals': 'égal à',
	'not equal': 'différent de',
	'begins with': 'commence par',
	'ends with': 'finit par',
	'is empty': 'est vide',
	'is not empty': "n'est pas vide",
	'lower or equal': 'inférieur ou égal',
	'greater or equal': 'supérieur ou égal',
	'in (comma separated)': 'parmi (séparés par des virgules)',

	// form
	'Create': 'Créer',
	'Save': 'Enregistrer',
	'Cancel': 'Annuler',
	'Close': 'Fermer',
	'Saved': 'Enregistré',
	'Created': 'Créé',
	'Deleted': 'Supprimé',
	'[COUNT] deleted': '[COUNT] supprimé(s)',
	'Delete this [LABEL]?': 'Supprimer cet élément ([LABEL]) ?',
	'Delete [COUNT] records?': 'Supprimer [COUNT] enregistrements ?',
	'This action cannot be undone.': 'Cette action est irréversible.',
}

const dictionaries: Record<string, Record<string, string>> = {fr}

let language = (navigator.language || 'en').slice(0, 2).toLowerCase()

export function setLanguage(lang: string)
{

	language = lang
	document.documentElement.lang = lang

}

export function getLanguage(): string
{

	return language

}

/**
 * translate an english text
 */
export function t(text: string, params: Record<string, string | number> = {}): string
{

	let out = dictionaries[language]?.[text] ?? text
	for(const [key, value] of Object.entries(params))out = out.replaceAll(`[${key}]`, String(value))
	return out

}

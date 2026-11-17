// emojis of the picker of the chatter, generated from emojibase-data (MIT license): npm run emojis
// one file per language: [emoji, group, words searched (label and tags)]; skin tones (group 2) left out,
// emoji versions up to 13.1 only: the newer ones are missing from the system fonts of Windows 10 and older devices
import { readFileSync, writeFileSync } from 'node:fs'

const MAX_VERSION = 13.1

for(const lang of ['fr', 'en'])
{

	const data = JSON.parse(readFileSync(`node_modules/emojibase-data/${lang}/data.json`, 'utf8'))
	const list = data
		.filter((e) => e.group !== undefined && e.group !== 2 && e.version <= MAX_VERSION)
		.sort((a, b) => a.order - b.order)
		.map((e) => [e.emoji, e.group, [e.label, ...(e.tags ?? [])].join(' ').toLowerCase()])

	writeFileSync(`src/data/emojis-${lang}.json`, JSON.stringify(list))
	console.log(`src/data/emojis-${lang}.json: ${list.length} emojis`)

}

// markdown of the text files (preview of the .md): the text is escaped first, the html comes only from the patterns below
// headings, paragraphs, bold, italic, strikethrough, inline code, code blocks, lists (nested by indent), quotes, rules, tables, links and images (http, https, mailto, relative)

const escape = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')

// urls kept: http, https, mailto, anchors and relative paths (never javascript: or data:)
const safeUrl = (url: string) => /^(https?:\/\/|mailto:|#|\/|\.{0,2}\/|[\w-]+(\/|\.|$))/i.test(url) && !/^\s*(javascript|data|vbscript):/i.test(url) ? url : '#'

/**
 * inline formatting of an escaped line
 */
function inline(text: string): string
{

	// code spans, images and links set aside: the generated html is never formatted again
	const kept: string[] = []
	const keep = (html: string) => { kept.push(html); return `\uE000${kept.length - 1}\uE000` }

	const html = text
		.replace(/`([^`]+)`/g, (_, code: string) => keep(`<code>${code}</code>`))
		.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_, alt: string, url: string) => keep(`<img src="${safeUrl(url)}" alt="${alt}">`))
		.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label: string, url: string) => keep(`<a href="${safeUrl(url)}" target="_blank" rel="noopener noreferrer">${format(label)}</a>`))

	return format(html).replace(/\uE000(\d+)\uE000/g, (_, i: string) => kept[Number(i)])

}

// bold, italic, strikethrough; _ only around words (snake_case kept)
function format(text: string): string
{

	return text
		.replace(/\*\*(?=\S)(.+?)(?<=\S)\*\*/g, '<strong>$1</strong>')
		.replace(/(?<!\w)__(?=\S)(.+?)(?<=\S)__(?!\w)/g, '<strong>$1</strong>')
		.replace(/\*(?=\S)(.+?)(?<=\S)\*/g, '<em>$1</em>')
		.replace(/(?<!\w)_(?=\S)(.+?)(?<=\S)_(?!\w)/g, '<em>$1</em>')
		.replace(/~~(?=\S)(.+?)(?<=\S)~~/g, '<del>$1</del>')

}

const cells = (line: string) => line.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim())

/**
 * html of a markdown text, safe to insert (every character of the source escaped)
 */
export function markdown(source: string): string
{

	const lines = escape(source.replace(/\r\n?/g, '\n')).split('\n')
	const out: string[] = []
	let i = 0

	while(i < lines.length)
	{

		const line = lines[i]

		// code block
		const fence = line.match(/^\s*(```|~~~)/)
		if(fence)
		{

			const body: string[] = []
			i++
			while(i < lines.length && !lines[i].trim().startsWith(fence[1]))body.push(lines[i++])
			i++
			out.push(`<pre><code>${body.join('\n')}</code></pre>`)
			continue

		}

		// heading, rule
		const heading = line.match(/^(#{1,6})\s+(.*?)\s*#*$/)
		if(heading){ out.push(`<h${heading[1].length}>${inline(heading[2])}</h${heading[1].length}>`); i++; continue }
		if(/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)){ out.push('<hr>'); i++; continue }

		// table: header row, separator row, body rows
		if(line.includes('|') && i + 1 < lines.length && /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/.test(lines[i + 1]))
		{

			const head = cells(line)
			const rows: string[][] = []
			i += 2
			while(i < lines.length && lines[i].includes('|') && lines[i].trim())rows.push(cells(lines[i++]))
			out.push(`<table><thead><tr>${head.map((c) => `<th>${inline(c)}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${head.map((_, k) => `<td>${inline(r[k] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody></table>`)
			continue

		}

		// quote
		if(/^&gt;\s?/.test(line))
		{

			const body: string[] = []
			while(i < lines.length && /^&gt;\s?/.test(lines[i]))body.push(lines[i++].replace(/^&gt;\s?/, ''))
			out.push(`<blockquote>${markdownEscaped(body)}</blockquote>`)
			continue

		}

		// list: items of the same marker, nested by indent
		if(/^\s*([-*+]|\d+[.)])\s+/.test(line))
		{

			out.push(list(lines, i, (next) => { i = next }))
			continue

		}

		// paragraph: the lines until an empty line or another block
		if(line.trim())
		{

			const body: string[] = []
			while(i < lines.length && lines[i].trim() && !/^(#{1,6}\s|\s*(```|~~~)|&gt;|\s*([-*+]|\d+[.)])\s+)/.test(lines[i]))body.push(lines[i++].trim())
			if(!body.length)body.push(lines[i++].trim())
			out.push(`<p>${inline(body.join(' '))}</p>`)
			continue

		}

		i++

	}

	return out.join('\n')

}

// blocks of already escaped lines (content of a quote)
function markdownEscaped(lines: string[]): string
{

	const unescape = (text: string) => text.replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&gt;/g, '>').replace(/&lt;/g, '<').replace(/&amp;/g, '&')
	return markdown(unescape(lines.join('\n')))

}

// list starting at line start, its nested lists by indent
function list(lines: string[], start: number, done: (next: number) => void): string
{

	const indent = (line: string) => line.match(/^\s*/)?.[0].length ?? 0
	const base = indent(lines[start])
	const ordered = /^\s*\d+[.)]\s+/.test(lines[start])
	const items: string[] = []
	let i = start

	while(i < lines.length)
	{

		const line = lines[i]
		const item = line.match(/^(\s*)([-*+]|\d+[.)])\s+(.*)$/)
		if(!item || indent(line) < base)break
		// another kind of list at the same level starts a new list
		if(indent(line) === base && /^\d/.test(item[2]) !== ordered)break

		if(indent(line) > base)
		{

			// nested list in the last item
			const nested = list(lines, i, (next) => { i = next })
			if(items.length)items[items.length - 1] += nested
			continue

		}

		const task = item[3].match(/^\[( |x|X)\]\s+(.*)$/)
		items.push(task ? `<input type="checkbox" disabled${task[1].trim() ? ' checked' : ''}> ${inline(task[2])}` : inline(item[3]))
		i++

	}

	done(i)
	const tag = ordered ? 'ol' : 'ul'
	return `<${tag}>${items.map((it) => `<li>${it}</li>`).join('')}</${tag}>`

}

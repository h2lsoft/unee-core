// coloring of the text editor for markdown, yaml and ini (stream modes of codemirror, no extra package)
// token names = highlight tags of @lezer/highlight, styled by the light and dark themes of the editor
import { StreamLanguage, type StringStream } from '@codemirror/language'

type MarkdownState = {code: boolean}

// inline parts of a markdown line, the rest of the text up to the next special character
function markdownInline(stream: StringStream): string | null
{

	if(stream.match(/^`[^`]+`/))return 'literal'
	if(stream.match(/^\*\*(?=\S)[^*]*?\S\*\*/) || stream.match(/^__(?=\S)[^_]*?\S__(?!\w)/))return 'strong'
	if(stream.match(/^\*(?=\S)[^*]*?\S\*/) || stream.match(/^_(?=\S)[^_]*?\S_(?!\w)/))return 'emphasis'
	if(stream.match(/^~~(?=\S)[^~]*?\S~~/))return 'strikethrough'
	if(stream.match(/^!?\[[^\]]*\]/))return 'link'
	if(stream.match(/^\([^)\s]*\)/))return 'url'
	if(stream.match(/^<https?:\/\/[^>\s]+>/))return 'url'
	if(stream.match(/^\|/))return 'meta'
	if(!stream.eatWhile(/[^`*_~![(<|]/))stream.next()
	return null

}

export const markdownMode = StreamLanguage.define<MarkdownState>({
	name: 'markdown',
	startState: () => ({code: false}),
	copyState: (state) => ({...state}),
	token(stream, state)
	{

		if(stream.sol())
		{

			// code block: fences and content
			if(stream.match(/^\s*(```|~~~)/))
			{

				state.code = !state.code
				stream.skipToEnd()
				return 'meta'

			}
			if(state.code){ stream.skipToEnd(); return 'literal' }

			if(stream.match(/^#{1,6}\s.*$/))return 'heading'
			if(stream.match(/^\s*([-*_])(\s*\1){2,}\s*$/))return 'contentSeparator'
			if(stream.match(/^\s*>.*$/))return 'comment'
			if(stream.match(/^\s*([-*+]|\d+[.)])\s+(\[[ xX]\]\s+)?/))return 'keyword'

		}

		if(state.code){ stream.skipToEnd(); return 'literal' }
		return markdownInline(stream)

	},
})

// yaml: comments, keys, list markers, quoted strings, numbers, booleans
export const yamlMode = StreamLanguage.define<Record<string, never>>({
	name: 'yaml',
	startState: () => ({}),
	token(stream)
	{

		if(stream.eatSpace())return null
		if(stream.match(/^#.*$/))return 'comment'
		if(stream.sol() && stream.match(/^(---|\.\.\.)\s*$/))return 'meta'
		if(stream.match(/^-(?=\s|$)/))return 'keyword'
		if(stream.match(/^[\w.$-]+(?=\s*:(\s|$))/) || stream.match(/^"[^"]*"(?=\s*:(\s|$))/))return 'propertyName.definition'
		if(stream.match(/^:(?=\s|$)/))return 'meta'
		if(stream.match(/^"(?:[^"\\]|\\.)*"?/) || stream.match(/^'(?:[^']|'')*'?/))return 'string'
		if(stream.match(/^[&*][\w-]+/))return 'labelName'
		if(stream.match(/^(true|false|yes|no|on|off|null|~)(?=\s*(#|$|,|\]|\}))/i))return 'bool'
		if(stream.match(/^[-+]?(\d[\d_]*(\.\d+)?([eE][-+]?\d+)?|0x[\da-fA-F]+)(?=\s*(#|$|,|\]|\}))/))return 'number'
		if(!stream.eatWhile(/[^\s#"':]/))stream.next()
		return null

	},
})

// ini: comments, [sections], keys, quoted values, numbers, booleans
export const iniMode = StreamLanguage.define<Record<string, never>>({
	name: 'ini',
	startState: () => ({}),
	token(stream)
	{

		if(stream.sol())
		{

			if(stream.match(/^\s*[;#].*$/))return 'comment'
			if(stream.match(/^\s*\[[^\]]*\]/))return 'heading'
			if(stream.match(/^\s*[^=\s][^=]*?(?=\s*=)/))return 'propertyName.definition'

		}
		if(stream.eatSpace())return null
		if(stream.match(/^=/))return 'meta'
		if(stream.match(/^"(?:[^"\\]|\\.)*"?/) || stream.match(/^'[^']*'?/))return 'string'
		if(stream.match(/^(true|false|on|off|yes|no|null)\b/i))return 'bool'
		if(stream.match(/^-?\d+(\.\d+)?\b/))return 'number'
		if(stream.match(/^[;#].*$/))return 'comment'
		stream.next()
		return null

	},
})

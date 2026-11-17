// operations of the image editor of the drive: each one returns a new canvas (history of the editor = list of canvases)

export type Rect = {x: number, y: number, w: number, h: number}
export type Adjust = {brightness: number, contrast: number, saturation: number, filter: 'none' | 'grayscale' | 'sepia'}
export type Shape =
	| {kind: 'arrow', x1: number, y1: number, x2: number, y2: number, color: string, width: number}
	| {kind: 'rect', x: number, y: number, w: number, h: number, color: string, width: number}
	| {kind: 'text', x: number, y: number, text: string, color: string, size: number}

export const NO_ADJUST: Adjust = {brightness: 100, contrast: 100, saturation: 100, filter: 'none'}

export function newCanvas(width: number, height: number): HTMLCanvasElement
{

	const canvas = document.createElement('canvas')
	canvas.width = Math.max(1, Math.round(width))
	canvas.height = Math.max(1, Math.round(height))
	return canvas

}

const context = (canvas: HTMLCanvasElement) => canvas.getContext('2d', {willReadFrequently: true}) as CanvasRenderingContext2D

export function clone(source: HTMLCanvasElement): HTMLCanvasElement
{

	const canvas = newCanvas(source.width, source.height)
	context(canvas).drawImage(source, 0, 0)
	return canvas

}

/**
 * image of the file drawn on a canvas (the browsers apply the exif orientation)
 */
export function fromImage(image: HTMLImageElement): HTMLCanvasElement
{

	const canvas = newCanvas(image.naturalWidth, image.naturalHeight)
	context(canvas).drawImage(image, 0, 0)
	return canvas

}

// quarter turn: 1 = right, -1 = left
export function rotate(source: HTMLCanvasElement, direction: 1 | -1): HTMLCanvasElement
{

	const canvas = newCanvas(source.height, source.width)
	const ctx = context(canvas)
	ctx.translate(canvas.width / 2, canvas.height / 2)
	ctx.rotate(direction * Math.PI / 2)
	ctx.drawImage(source, -source.width / 2, -source.height / 2)
	return canvas

}

export function flip(source: HTMLCanvasElement, horizontal: boolean): HTMLCanvasElement
{

	const canvas = newCanvas(source.width, source.height)
	const ctx = context(canvas)
	ctx.translate(horizontal ? canvas.width : 0, horizontal ? 0 : canvas.height)
	ctx.scale(horizontal ? -1 : 1, horizontal ? 1 : -1)
	ctx.drawImage(source, 0, 0)
	return canvas

}

export function crop(source: HTMLCanvasElement, rect: Rect): HTMLCanvasElement
{

	const r = normalize(rect, source.width, source.height)
	const canvas = newCanvas(r.w, r.h)
	context(canvas).drawImage(source, r.x, r.y, r.w, r.h, 0, 0, r.w, r.h)
	return canvas

}

/**
 * new size; a big reduction goes by halves for a smoother result
 */
export function resize(source: HTMLCanvasElement, width: number, height: number): HTMLCanvasElement
{

	let current = source
	while(current.width / 2 > width && current.height / 2 > height)
	{

		const half = newCanvas(current.width / 2, current.height / 2)
		const ctx = context(half)
		ctx.imageSmoothingQuality = 'high'
		ctx.drawImage(current, 0, 0, half.width, half.height)
		current = half

	}

	const canvas = newCanvas(width, height)
	const ctx = context(canvas)
	ctx.imageSmoothingQuality = 'high'
	ctx.drawImage(current, 0, 0, canvas.width, canvas.height)
	return canvas

}

// css filter of the live preview, same order as adjust()
export const cssFilter = (a: Adjust) => `brightness(${a.brightness}%) contrast(${a.contrast}%) saturate(${a.saturation}%)${(a.filter === 'grayscale') ? ' grayscale(1)' : ''}${(a.filter === 'sepia') ? ' sepia(1)' : ''}`

/**
 * brightness, contrast, saturation then black and white or sepia, by pixel (formulas of the css filters: same result as the preview, every browser)
 */
export function adjust(source: HTMLCanvasElement, a: Adjust): HTMLCanvasElement
{

	const canvas = clone(source)
	const ctx = context(canvas)
	const image = ctx.getImageData(0, 0, canvas.width, canvas.height)
	const d = image.data
	const b = a.brightness / 100
	const k = a.contrast / 100
	const s = a.saturation / 100
	const sat = [0.213 + 0.787 * s, 0.715 - 0.715 * s, 0.072 - 0.072 * s, 0.213 - 0.213 * s, 0.715 + 0.285 * s, 0.072 - 0.072 * s, 0.213 - 0.213 * s, 0.715 - 0.715 * s, 0.072 + 0.928 * s]
	const last = (a.filter === 'grayscale')
		? [0.2126, 0.7152, 0.0722, 0.2126, 0.7152, 0.0722, 0.2126, 0.7152, 0.0722]
		: ((a.filter === 'sepia') ? [0.393, 0.769, 0.189, 0.349, 0.686, 0.168, 0.272, 0.534, 0.131] : null)
	const clamp = (v: number) => (v < 0) ? 0 : ((v > 1) ? 1 : v)
	const matrix = (m: number[], r: number, g: number, bl: number) => [clamp(m[0] * r + m[1] * g + m[2] * bl), clamp(m[3] * r + m[4] * g + m[5] * bl), clamp(m[6] * r + m[7] * g + m[8] * bl)]

	for(let i = 0; i < d.length; i += 4)
	{

		let r = clamp(d[i] / 255 * b)
		let g = clamp(d[i + 1] / 255 * b)
		let bl = clamp(d[i + 2] / 255 * b)
		r = clamp((r - 0.5) * k + 0.5)
		g = clamp((g - 0.5) * k + 0.5)
		bl = clamp((bl - 0.5) * k + 0.5);
		[r, g, bl] = matrix(sat, r, g, bl)
		if(last)[r, g, bl] = matrix(last, r, g, bl)
		d[i] = Math.round(r * 255)
		d[i + 1] = Math.round(g * 255)
		d[i + 2] = Math.round(bl * 255)

	}

	ctx.putImageData(image, 0, 0)
	return canvas

}

/**
 * zone pixelated (faces, plates, personal data): blocks of about 1/40 of the image, 8 px at least
 */
export function pixelate(source: HTMLCanvasElement, rect: Rect): HTMLCanvasElement
{

	const r = normalize(rect, source.width, source.height)
	const block = Math.max(8, Math.round(Math.max(source.width, source.height) / 40))
	const small = newCanvas(Math.max(1, Math.ceil(r.w / block)), Math.max(1, Math.ceil(r.h / block)))
	context(small).drawImage(source, r.x, r.y, r.w, r.h, 0, 0, small.width, small.height)

	const canvas = clone(source)
	const ctx = context(canvas)
	ctx.imageSmoothingEnabled = false
	ctx.drawImage(small, 0, 0, small.width, small.height, r.x, r.y, r.w, r.h)
	return canvas

}

/**
 * arrow, rectangle or text drawn on the image (the text with a thin outline of the opposite tone, readable on any background)
 */
export function draw(source: HTMLCanvasElement, shape: Shape): HTMLCanvasElement
{

	const canvas = clone(source)
	drawShape(context(canvas), shape)
	return canvas

}

// shape on a context (also used by the overlay of the editor while drawing)
export function drawShape(ctx: CanvasRenderingContext2D, shape: Shape)
{

	ctx.save()
	ctx.lineCap = 'round'
	ctx.lineJoin = 'round'

	if(shape.kind === 'rect')
	{

		ctx.strokeStyle = shape.color
		ctx.lineWidth = shape.width
		ctx.strokeRect(shape.x, shape.y, shape.w, shape.h)

	}
	else if(shape.kind === 'arrow')
	{

		const angle = Math.atan2(shape.y2 - shape.y1, shape.x2 - shape.x1)
		const head = shape.width * 4.5
		ctx.strokeStyle = shape.color
		ctx.fillStyle = shape.color
		ctx.lineWidth = shape.width
		ctx.beginPath()
		ctx.moveTo(shape.x1, shape.y1)
		ctx.lineTo(shape.x2 - Math.cos(angle) * head * 0.6, shape.y2 - Math.sin(angle) * head * 0.6)
		ctx.stroke()
		ctx.beginPath()
		ctx.moveTo(shape.x2, shape.y2)
		ctx.lineTo(shape.x2 - head * Math.cos(angle - Math.PI / 7), shape.y2 - head * Math.sin(angle - Math.PI / 7))
		ctx.lineTo(shape.x2 - head * Math.cos(angle + Math.PI / 7), shape.y2 - head * Math.sin(angle + Math.PI / 7))
		ctx.closePath()
		ctx.fill()

	}
	else
	{

		ctx.font = `600 ${shape.size}px "IBM Plex Sans", system-ui, sans-serif`
		ctx.textBaseline = 'top'
		ctx.lineWidth = Math.max(2, shape.size / 7)
		ctx.strokeStyle = isLight(shape.color) ? 'rgba(0, 0, 0, .55)' : 'rgba(255, 255, 255, .7)'
		shape.text.split('\n').forEach((line, i) => {

			ctx.strokeText(line, shape.x, shape.y + i * shape.size * 1.2)
			ctx.fillStyle = shape.color
			ctx.fillText(line, shape.x, shape.y + i * shape.size * 1.2)

		})

	}

	ctx.restore()

}

// #rrggbb light enough for a dark outline
function isLight(color: string): boolean
{

	const m = color.match(/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i)
	if(!m)return true
	const [r, g, b] = [m[1], m[2], m[3]].map((h) => parseInt(h, 16))
	return (0.299 * r + 0.587 * g + 0.114 * b) > 150

}

// rectangle drawn in any direction, kept in the image, integer pixels
export function normalize(rect: Rect, width: number, height: number): Rect
{

	const x1 = Math.max(0, Math.min(rect.x, rect.x + rect.w))
	const y1 = Math.max(0, Math.min(rect.y, rect.y + rect.h))
	const x2 = Math.min(width, Math.max(rect.x, rect.x + rect.w))
	const y2 = Math.min(height, Math.max(rect.y, rect.y + rect.h))
	return {x: Math.round(x1), y: Math.round(y1), w: Math.max(1, Math.round(x2 - x1)), h: Math.max(1, Math.round(y2 - y1))}

}

/**
 * file content of the canvas; jpg has no transparency: white background
 *
 * @param type image/jpeg, image/png, image/webp
 * @param quality 0 to 1 (jpg, webp)
 */
export function toBlob(source: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null>
{

	let canvas = source
	if(type === 'image/jpeg')
	{

		canvas = newCanvas(source.width, source.height)
		const ctx = context(canvas)
		ctx.fillStyle = '#fff'
		ctx.fillRect(0, 0, canvas.width, canvas.height)
		ctx.drawImage(source, 0, 0)

	}

	return new Promise((resolve) => canvas.toBlob(resolve, type, quality))

}

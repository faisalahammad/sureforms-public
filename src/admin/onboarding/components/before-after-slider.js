import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { ChevronsLeftRight } from 'lucide-react';

/**
 * Before/after comparison. `before` fills the box; `after` is revealed from
 * the right edge up to a draggable divider. Dragging is a transparent native
 * range input laid over the whole box, so it works with mouse, touch and
 * keyboard without any extra code.
 *
 * @param {Object} props
 * @param {Node}   props.before  Full-size "before" card.
 * @param {Node}   props.after   Full-size "after" card (same dimensions).
 * @param {number} props.width   Box width in px.
 * @param {number} props.height  Box height in px.
 * @param {number} props.initial Initial divider position, 0–100 (% from the left).
 * @param {string} props.label   Accessible name for the drag handle.
 * @return {JSX.Element} The slider.
 */
const BeforeAfterSlider = ( {
	before,
	after,
	width = 283,
	height = 246,
	initial = 50,
	label = __( 'Compare free and premium', 'sureforms' ),
} ) => {
	const [ position, setPosition ] = useState( initial );

	return (
		<div style={ { width } }>
			<div
				className="relative select-none overflow-hidden rounded-[13px] focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-button-primary"
				style={ { width, height } }
			>
				<div className="absolute inset-0">{ before }</div>
				<div
					className="absolute inset-y-0 right-0 overflow-hidden"
					style={ { width: `${ 100 - position }%` } }
				>
					<div className="absolute right-0 top-0" style={ { width } }>
						{ after }
					</div>
				</div>
				<div
					className="pointer-events-none absolute inset-y-0 w-0.5 -translate-x-1/2 bg-button-primary"
					style={ { left: `${ position }%` } }
				>
					<span className="absolute left-1/2 top-1/2 flex size-6 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-button-primary text-white shadow-md">
						<ChevronsLeftRight className="size-3.5" />
					</span>
				</div>
				<input
					type="range"
					min={ 0 }
					max={ 100 }
					value={ position }
					onChange={ ( event ) =>
						setPosition( Number( event.target.value ) )
					}
					aria-label={ label }
					aria-valuetext={ sprintf(
						/* translators: %s: percentage, already formatted, e.g. "20%". */
						__( '%s of the free version shown', 'sureforms' ),
						`${ position }%`
					) }
					className="absolute inset-0 m-0 h-full w-full cursor-ew-resize appearance-none bg-transparent opacity-0"
				/>
			</div>
		</div>
	);
};

export default BeforeAfterSlider;

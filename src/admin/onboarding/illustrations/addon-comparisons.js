import { __ } from '@wordpress/i18n';
import conversationalPro from '@Image/onboarding/addon-conversational-pro.svg';
import multistepFree from '@Image/onboarding/addon-multistep-free.svg';

/*
 * Free vs Pro mock cards for the add-ons step, one pair per tab. Copy and
 * layout follow the Figma "Upgrade Comparison Visuals" frame. Sample names,
 * prices and percentages are illustrative data.
 */

const Card = ( { title, chip, pro = false, children } ) => (
	<div
		className={ `flex h-[246px] w-[283px] flex-col gap-1.5 overflow-hidden rounded-[13px] border-[1.5px] border-solid border-[#e2e8f0] p-[18px] ${
			pro ? 'bg-[#f0fdf4]' : 'bg-[#f8fafc]'
		}` }
		aria-hidden="true"
	>
		<div className="flex items-center">
			<span className="flex-1 text-[12.5px] font-bold leading-none text-[#0f172a]">
				{ title }
			</span>
			<span
				className={ `rounded border border-solid px-2 py-[3px] text-[9.5px] font-semibold leading-none ${
					pro
						? 'border-[#fddccc] bg-[#fff4ef] text-[#1f2937]'
						: 'border-[#e2e8f0] bg-white text-[#94a3b8]'
				}` }
			>
				{ chip }
			</span>
		</div>
		<div className="h-1.5" />
		{ children }
	</div>
);

const Field = ( { label } ) => (
	<div className="flex w-full min-w-0 shrink-0 items-center overflow-hidden rounded-[7px] border border-solid border-[#e2e8f0] bg-white px-[11px] py-2">
		<span className="truncate text-[11.5px] leading-none text-[#64748b]">
			{ label }
		</span>
	</div>
);

// Side-by-side fields share the row width equally.
const Row = ( { children } ) => (
	<div className="flex gap-1.5 [&>*]:min-w-0 [&>*]:flex-1">{ children }</div>
);

const Label = ( { children } ) => (
	<span className="text-[9px] font-semibold uppercase leading-none tracking-wide text-[#64748b]">
		{ children }
	</span>
);

const Pill = ( { children, active = false } ) => (
	<span
		className={ `flex-1 rounded-[7px] border border-solid px-1.5 py-1.5 text-center text-[10.5px] leading-none ${
			active
				? 'border-[#fddccc] bg-[#fff4ef] font-semibold text-[#d54407]'
				: 'border-[#e2e8f0] bg-white text-[#64748b]'
		}` }
	>
		{ children }
	</span>
);

const DarkButton = ( { children, className = '' } ) => (
	<div
		className={ `flex items-center justify-center rounded-[7px] bg-[#1f2937] py-[9px] ${ className }` }
	>
		<span className="text-xs font-semibold leading-none text-white">
			{ children }
		</span>
	</div>
);

const Note = ( { children } ) => (
	<span className="text-center text-[10.5px] font-semibold leading-none text-[#1f2937]">
		{ children }
	</span>
);

const PriceRow = ( { label, price, total = false } ) => (
	<div
		className={ `flex items-center justify-between rounded-[7px] border border-solid px-[11px] py-2 text-[11.5px] leading-none ${
			total
				? 'border-[#fddccc] bg-[#fff4ef] font-semibold text-[#1f2937]'
				: 'border-[#e2e8f0] bg-white text-[#64748b]'
		}` }
	>
		<span>{ label }</span>
		<span
			className={
				total ? 'text-[#d54407]' : 'font-medium text-[#1f2937]'
			}
		>
			{ price }
		</span>
	</div>
);

const Progress = ( { segments, filled } ) => (
	<div className="flex gap-[5px]">
		{ Array.from( { length: segments }, ( _, index ) => (
			<span
				key={ index }
				className={ `h-1 flex-1 rounded-full ${
					index < filled ? 'bg-[#d54407]' : 'bg-[#e2e8f0]'
				}` }
			/>
		) ) }
	</div>
);

/* ---------- Multistep ---------- */

// Designed artwork (283x246) rather than a hand-built card. The mock has the
// seven fields QA asked for; the free/pro pair is drifting to exported SVGs one
// card at a time, so Card/Field/Row below still serve the rest.
const MultistepFree = () => (
	<img
		src={ multistepFree }
		alt=""
		width={ 283 }
		height={ 246 }
		className="block h-[246px] w-[283px]"
	/>
);

const MultistepPro = () => (
	<Card
		title={ __( 'Personal Details', 'sureforms' ) }
		chip={ __( 'Step 1 of 3', 'sureforms' ) }
		pro
	>
		<Progress segments={ 3 } filled={ 1 } />
		<div className="h-2" />
		<Field label={ __( 'Full name', 'sureforms' ) } />
		<Field label={ __( 'Email address', 'sureforms' ) } />
		<Field label={ __( 'Company name', 'sureforms' ) } />
		<div className="h-0.5" />
		<DarkButton>{ __( 'Continue', 'sureforms' ) }</DarkButton>
		<div className="h-1.5" />
		<Note>{ __( 'Two more steps, three fields each', 'sureforms' ) }</Note>
	</Card>
);

/* ---------- Conditional ---------- */

const ConditionalFree = () => (
	<Card
		title={ __( 'Registration', 'sureforms' ) }
		chip={ __( '6 fields', 'sureforms' ) }
	>
		<Row>
			<Field label={ __( 'Full name', 'sureforms' ) } />
			<Field label={ __( 'Email address', 'sureforms' ) } />
		</Row>
		<Field label={ __( 'I am a…', 'sureforms' ) } />
		<Row>
			<Field label={ __( 'School name', 'sureforms' ) } />
			<Field label={ __( 'Graduation year', 'sureforms' ) } />
		</Row>
		<Row>
			<Field label={ __( 'Company name', 'sureforms' ) } />
			<Field label={ __( 'Job title', 'sureforms' ) } />
		</Row>
	</Card>
);

const ConditionalPro = () => (
	<Card
		title={ __( 'Registration', 'sureforms' ) }
		chip={ __( '3 fields', 'sureforms' ) }
		pro
	>
		<Label>{ __( 'I am a', 'sureforms' ) }</Label>
		<Row>
			<Pill>{ __( 'Student', 'sureforms' ) }</Pill>
			<Pill active>{ __( 'Employee', 'sureforms' ) }</Pill>
			<Pill>{ __( 'Business', 'sureforms' ) }</Pill>
		</Row>
		<Field label={ __( 'Company name', 'sureforms' ) } />
		<Field label={ __( 'Job title', 'sureforms' ) } />
		<div className="h-0.5" />
		<DarkButton>{ __( 'Submit', 'sureforms' ) }</DarkButton>
		<div className="h-1.5" />
		<Note>{ __( 'Three fields stayed hidden', 'sureforms' ) }</Note>
	</Card>
);

/* ---------- Calculation ---------- */

const CalculationFree = () => (
	<Card
		title={ __( 'Get a quote', 'sureforms' ) }
		chip={ __( 'No pricing', 'sureforms' ) }
	>
		<Field label={ __( 'Website design', 'sureforms' ) } />
		<Field label={ __( 'SEO setup', 'sureforms' ) } />
		<Field label={ __( 'Content pages', 'sureforms' ) } />
		<Field label={ __( 'Anything else', 'sureforms' ) } />
		<div className="h-0.5" />
		<DarkButton>{ __( 'Request a quote', 'sureforms' ) }</DarkButton>
		<Note>{ __( 'You reply with a price later', 'sureforms' ) }</Note>
	</Card>
);

const CalculationPro = () => (
	<Card
		title={ __( 'Get a quote', 'sureforms' ) }
		chip={ __( 'Live total', 'sureforms' ) }
		pro
	>
		<PriceRow
			label={ __( 'Website design', 'sureforms' ) }
			price="$2,500"
		/>
		<PriceRow label={ __( 'SEO setup', 'sureforms' ) } price="$800" />
		<PriceRow
			label={ __( 'Content, 5 pages', 'sureforms' ) }
			price="$1,250"
		/>
		<PriceRow
			label={ __( 'Your estimate', 'sureforms' ) }
			price="$4,550"
			total
		/>
		<div className="h-0.5" />
		<DarkButton>{ __( 'Book this in', 'sureforms' ) }</DarkButton>
	</Card>
);

/* ---------- Conversational ---------- */

const ConversationalFree = () => (
	<Card
		title={ __( 'Product Review', 'sureforms' ) }
		chip={ __( '8 fields', 'sureforms' ) }
	>
		<Row>
			<Field label={ __( 'Full name', 'sureforms' ) } />
			<Field label={ __( 'Email address', 'sureforms' ) } />
		</Row>
		<Row>
			<Field label={ __( 'Company name', 'sureforms' ) } />
			<Field label={ __( 'Job title', 'sureforms' ) } />
		</Row>
		<Field label={ __( 'What do you like most about…', 'sureforms' ) } />
		<Field
			label={ __(
				'Which feature do you find most useful?',
				'sureforms'
			) }
		/>
		<Row>
			<Field label={ __( 'Company size', 'sureforms' ) } />
			<Field label={ __( 'Phone number', 'sureforms' ) } />
		</Row>
	</Card>
);

// Designed artwork (283×246, static) rather than a hand-built card.
const ConversationalPro = () => (
	<img
		src={ conversationalPro }
		alt=""
		width={ 283 }
		height={ 246 }
		className="block h-[246px] w-[283px]"
	/>
);

const ADDON_COMPARISONS = {
	multistep: { free: <MultistepFree />, pro: <MultistepPro /> },
	conditional: { free: <ConditionalFree />, pro: <ConditionalPro /> },
	calculation: { free: <CalculationFree />, pro: <CalculationPro /> },
	conversational: {
		free: <ConversationalFree />,
		pro: <ConversationalPro />,
	},
};

export default ADDON_COMPARISONS;

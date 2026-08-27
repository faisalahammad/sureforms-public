import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Input, Loader, Select, Switch, toast } from '@bsf/force-ui';
import ContentSection from '../components/ContentSection';
import LoadingSkeleton from '@Admin/components/LoadingSkeleton';

const days = [
	{ label: __( 'Monday', 'sureforms' ), value: 'Monday' },
	{ label: __( 'Tuesday', 'sureforms' ), value: 'Tuesday' },
	{ label: __( 'Wednesday', 'sureforms' ), value: 'Wednesday' },
	{ label: __( 'Thursday', 'sureforms' ), value: 'Thursday' },
	{ label: __( 'Friday', 'sureforms' ), value: 'Friday' },
	{ label: __( 'Saturday', 'sureforms' ), value: 'Saturday' },
	{ label: __( 'Sunday', 'sureforms' ), value: 'Sunday' },
];

/**
 * Email Summaries settings section.
 *
 * @param {Object}   props
 * @param {Object}   props.emailTabOptions      - Email summary settings.
 * @param {Function} props.updateGlobalSettings - Settings update handler.
 */
const EmailSummariesContent = ( { emailTabOptions, updateGlobalSettings } ) => {
	const [ sendingTestEmail, setSendingTestEmail ] = useState( false );

	const getReportsLabel = ( value ) => {
		const selectedDay = days.find( ( day ) => day.value === value );
		return selectedDay ? selectedDay.label : '';
	};

	return (
		<>
			<Switch
				label={ {
					heading: __( 'Enable email summaries', 'sureforms' ),
				} }
				value={ emailTabOptions.srfm_email_summary }
				onChange={ ( value ) =>
					updateGlobalSettings(
						'srfm_email_summary',
						value,
						'email-settings'
					)
				}
			/>
			{ emailTabOptions.srfm_email_summary && (
				<>
					<div className="flex items-end gap-2">
						<div className="flex-1">
							<Input
								size="md"
								label={ __( 'Send Email To', 'sureforms' ) }
								type="email"
								value={ emailTabOptions.srfm_email_sent_to }
								onChange={ ( value ) =>
									updateGlobalSettings(
										'srfm_email_sent_to',
										value,
										'email-settings'
									)
								}
								required
								autoComplete="off"
							/>
						</div>
						<Button
							variant="outline"
							size="md"
							icon={ sendingTestEmail && <Loader /> }
							iconPosition="left"
							onClick={ async () => {
								if ( sendingTestEmail ) {
									return;
								}

								setSendingTestEmail( true );
								try {
									await apiFetch( {
										path: '/sureforms/v1/send-test-email-summary',
										method: 'POST',
										data: {
											srfm_email_sent_to:
												emailTabOptions.srfm_email_sent_to,
										},
									} ).then( ( response ) => {
										setSendingTestEmail( false );
										toast.success( response?.data );
									} );
								} catch ( error ) {
									setSendingTestEmail( false );
									toast.error( error?.data );
									console.error(
										'Error Sending Test Email Summary:',
										error
									);
								}
							} }
							className="bg-background-secondary"
						>
							{ __( 'Test Email', 'sureforms' ) }
						</Button>
					</div>
					<Select
						value={ getReportsLabel(
							emailTabOptions.srfm_schedule_report
						) }
						onChange={ ( value ) =>
							updateGlobalSettings(
								'srfm_schedule_report',
								value,
								'email-settings'
							)
						}
					>
						<Select.Button
							type="button"
							label={ __( 'Schedule Reports', 'sureforms' ) }
						/>
						<Select.Portal id="srfm-settings-container">
							<Select.Options>
								{ days.map( ( day ) => (
									<Select.Option
										key={ day.value }
										value={ day.value }
									>
										{ day.label }
									</Select.Option>
								) ) }
							</Select.Options>
						</Select.Portal>
					</Select>
				</>
			) }
		</>
	);
};

/**
 * IP Logging settings section.
 *
 * @param {Object}   props
 * @param {Object}   props.generalTabOptions    - General settings.
 * @param {Function} props.updateGlobalSettings - Settings update handler.
 */
const IPLoggingContent = ( { generalTabOptions, updateGlobalSettings } ) => {
	return (
		<>
			<Switch
				label={ {
					heading: __( 'Enable IP logging', 'sureforms' ),
					description: __(
						"If this option is turned on, the user's IP address will be saved with the form data",
						'sureforms'
					),
				} }
				value={ generalTabOptions.srfm_ip_log }
				onChange={ ( value ) =>
					updateGlobalSettings(
						'srfm_ip_log',
						value,
						'general-settings'
					)
				}
			/>
		</>
	);
};

/**
 * Admin Notification settings section.
 *
 * @param {Object}   props
 * @param {Object}   props.generalTabOptions    - General settings.
 * @param {Function} props.updateGlobalSettings - Settings update handler.
 * @param {boolean}  props.showLearnTip         - Whether to show the Learn section guidance tooltip.
 */
const AdminNotificationContent = ( {
	generalTabOptions,
	updateGlobalSettings,
	showLearnTip,
} ) => {
	return (
		<div className="relative">
			<Switch
				label={ {
					heading: __( 'Enable Admin Notification', 'sureforms' ),
					description: __(
						'Admin notifications keep you informed about new form entries since your last visit.',
						'sureforms'
					),
				} }
				value={ generalTabOptions.srfm_admin_notification }
				onChange={ ( value ) =>
					updateGlobalSettings(
						'srfm_admin_notification',
						value,
						'general-settings'
					)
				}
			/>
			{ showLearnTip && (
				<div className="absolute top-full left-1/3 -translate-x-1/2 mt-2 z-[999999] pointer-events-none">
					<div className="absolute -top-[5px] left-1/2 -translate-x-1/2 w-2.5 h-2.5 bg-[#1e1e1e] rotate-45" />
					<div className="bg-[#1e1e1e] text-white text-sm px-3 py-1.5 rounded-md shadow-md whitespace-nowrap">
						{ __(
							'Turn on Admin Notification from here.',
							'sureforms'
						) }
					</div>
				</div>
			) }
		</div>
	);
};

/**
 * Usage Tracking / Analytics settings section.
 *
 * @param {Object}   props
 * @param {Object}   props.generalTabOptions    - General settings.
 * @param {Function} props.updateGlobalSettings - Settings update handler.
 */
const UsageTrackingContent = ( {
	generalTabOptions,
	updateGlobalSettings,
} ) => {
	return (
		<Switch
			label={ {
				heading: __( 'Help shape the future of SureForms', 'sureforms' ),
				description: (
					<>
						<p>
							{ __(
								'Collect non-sensitive information from your website, such as the PHP version and features used, to help us fix bugs faster, make smarter decisions, and build features that actually matter to you. ',
								'sureforms'
							) }
							<a
								href="https://sureforms.com/share-usage-data/"
								target="_blank"
								rel="noopener noreferrer"
								className="text-field-helper"
							>
								{ __( 'Learn More', 'sureforms' ) }
							</a>
						</p>
					</>
				),
			} }
			value={ generalTabOptions.srfm_bsf_analytics }
			onChange={ ( value ) =>
				updateGlobalSettings(
					'srfm_bsf_analytics',
					value,
					'general-settings'
				)
			}
		/>
	);
};

/**
 * Debug logging settings section.
 *
 * @param {Object}   props
 * @param {Object}   props.generalTabOptions    - General settings.
 * @param {Function} props.updateGlobalSettings - Settings update handler.
 * @param {Object}   props.logMeta              - Server-reported log size and expiry.
 * @param {Function} props.setLogMeta           - Updates the reported log status.
 */
// Mirrors Client_Logger::MAX_FILE_SIZE. Once the file is full it stops accepting
// lines rather than evicting the repro someone is trying to capture, so the UI has
// to say so — silently dropping new entries is the one bad outcome here.
const MAX_LOG_SIZE = 1048576;

const LogsContent = ( {
	generalTabOptions,
	updateGlobalSettings,
	logMeta,
	setLogMeta,
} ) => {
	const [ clearing, setClearing ] = useState( false );

	const nonce = window?.srfm_admin?.client_logs_nonce ?? '';
	const ajaxUrl = window?.srfm_admin?.ajax_url ?? '';
	const downloadUrl = `${ ajaxUrl }?action=srfm_download_logs&_wpnonce=${ nonce }`;

	const formatSize = ( bytes ) => {
		if ( bytes < 1024 ) {
			return `${ bytes } B`;
		}
		if ( bytes < 1048576 ) {
			return `${ Math.round( bytes / 1024 ) } KB`;
		}
		return `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;
	};

	const handleClear = async () => {
		if ( clearing ) {
			return;
		}
		setClearing( true );
		try {
			await fetch(
				`${ ajaxUrl }?action=srfm_clear_logs&_wpnonce=${ nonce }`,
				{ method: 'POST', credentials: 'same-origin' }
			);
			setLogMeta( { ...logMeta, size: 0 } );
			toast.success( __( 'Log cleared.', 'sureforms' ) );
		} catch ( error ) {
			toast.error( __( 'Could not clear the log.', 'sureforms' ) );
		} finally {
			setClearing( false );
		}
	};

	return (
		<>
			<Switch
				label={ {
					heading: __( 'Enable logs', 'sureforms' ),
					description: __(
						'Records form submission failures reported by the browser, so you can send them to support instead of reading the console. Turn this on only while reproducing a problem — it switches itself off after 7 days.',
						'sureforms'
					),
				} }
				value={ generalTabOptions.srfm_enable_logs }
				onChange={ ( value ) =>
					updateGlobalSettings(
						'srfm_enable_logs',
						value,
						'general-settings'
					)
				}
			/>
			{ generalTabOptions.srfm_enable_logs && (
				<div className="flex items-center gap-3">
					<Button
						variant="outline"
						size="md"
						tag="a"
						href={ downloadUrl }
						className="bg-background-secondary"
					>
						{ __( 'Download log', 'sureforms' ) }
					</Button>
					<Button
						variant="ghost"
						size="md"
						onClick={ handleClear }
						icon={ clearing && <Loader /> }
						iconPosition="left"
					>
						{ __( 'Clear', 'sureforms' ) }
					</Button>
					<span
						className={
							logMeta?.size >= MAX_LOG_SIZE
								? 'text-sm text-support-error'
								: 'text-sm text-text-secondary'
						}
					>
						{ logMeta?.size >= MAX_LOG_SIZE
							? __(
								'Log is full — download and clear it to keep recording.',
								'sureforms'
							  )
							: logMeta?.size
								? formatSize( logMeta.size )
								: __( 'Empty', 'sureforms' ) }
					</span>
				</div>
			) }
		</>
	);
};

const GeneralPage = ( {
	loading,
	generalTabOptions,
	emailTabOptions,
	updateGlobalSettings,
	logMeta,
	setLogMeta,
} ) => {
	// Detect if user arrived from the Learn section (email-notification lesson).
	const [ isLearnSource ] = useState(
		() =>
			new URLSearchParams( window.location.search ).get( 'source' ) ===
			'learn'
	);
	const [ showLearnTip, setShowLearnTip ] = useState( false );

	useEffect( () => {
		if ( ! isLearnSource ) {
			return;
		}
		const showTimer = setTimeout( () => setShowLearnTip( true ), 300 );
		const hideTimer = setTimeout( () => setShowLearnTip( false ), 5300 );
		return () => {
			clearTimeout( showTimer );
			clearTimeout( hideTimer );
		};
	}, [ isLearnSource ] );

	if ( loading ) {
		return (
			<div className="space-y-6">
				<LoadingSkeleton count={ 6 } className="h-6 rounded-sm" />
			</div>
		);
	}

	return (
		<div className="space-y-6">
			<ContentSection
				loading={ loading }
				title={ __( 'Email Summaries', 'sureforms' ) }
				content={
					<EmailSummariesContent
						emailTabOptions={ emailTabOptions }
						updateGlobalSettings={ updateGlobalSettings }
					/>
				}
			/>
			<ContentSection
				loading={ loading }
				title={ __( 'IP Logging', 'sureforms' ) }
				content={
					<IPLoggingContent
						generalTabOptions={ generalTabOptions }
						updateGlobalSettings={ updateGlobalSettings }
					/>
				}
			/>
			<ContentSection
				loading={ loading }
				title={ __( 'Admin Notification', 'sureforms' ) }
				content={
					<AdminNotificationContent
						generalTabOptions={ generalTabOptions }
						updateGlobalSettings={ updateGlobalSettings }
						showLearnTip={ showLearnTip }
					/>
				}
			/>
			<ContentSection
				loading={ loading }
				title={ __( 'Anonymous Analytics', 'sureforms' ) }
				content={
					<UsageTrackingContent
						generalTabOptions={ generalTabOptions }
						updateGlobalSettings={ updateGlobalSettings }
					/>
				}
			/>
			<ContentSection
				loading={ loading }
				title={ __( 'Logs', 'sureforms' ) }
				content={
					<LogsContent
						generalTabOptions={ generalTabOptions }
						updateGlobalSettings={ updateGlobalSettings }
						logMeta={ logMeta }
						setLogMeta={ setLogMeta }
					/>
				}
			/>
		</div>
	);
};

export default GeneralPage;

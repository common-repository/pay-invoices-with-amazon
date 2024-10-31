(function () {
	window.PIWAButtonRenderTimeouts = [];

	const {
		registerBlockType
	}                      = wp.blocks;
	const {
		TextControl,
		PanelBody,
		PanelRow
	}                      = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const {
		InspectorControls
	}                      = wp.blockEditor;
	const {
		createElement,
		useState,
		useEffect,
		useRef
	}                      = wp.element;
	const {
		useDispatch
	}                      = wp.data;
	const i18n             = PIWABlock;

	function ServerSideRenderWithCallback({
		block,
		attributes,
		onRender
	}) {
		const ref = useRef( null );

		useEffect(
			function () {
				const observer = new MutationObserver(
					function () {
						if (typeof onRender === 'function') {
							onRender( ref.current );
						}
					}
				);
				if (ref.current) {
					observer.observe(
						ref.current,
						{
							childList: true,
							subtree: true,
						}
					);
				}
				return () => observer.disconnect(); // Clean up on unmount
			},
			[block, attributes, onRender]
		);

		return createElement(
			'div',
			{
				ref
			},
			createElement(
				ServerSideRender,
				{
					block,
					attributes,
				}
			)
		);
	}

	function TitleInput(props) {
		const {
			value,
			onUpdate
		}                                 = props;
		const [inputValue, setInputValue] = useState( value || '' ); // Use a local state for the input value

		const handleInputChange = (event) => {
			const newInputValue = event.target.value;
			setInputValue( newInputValue ); // Update local state
			onUpdate( newInputValue );
		};

		return createElement(
			'input',
			{
				type: 'text',
				value: inputValue,
				onChange: handleInputChange,
				placeholder: i18n.title,
			}
		);
	}

	function AmountInput(props) {
		const {
			value,
			onUpdate
		}                                 = props;
		const [inputValue, setInputValue] = useState( value || '100.00' ); // Use a local state for the input value

		const handleInputChange = (event) => {
			const newInputValue = event.target.value;
			if ('' === newInputValue) {
				return;
			}
			setInputValue( newInputValue ); // Update local state
			onUpdate( newInputValue );
		};

		return createElement(
			'input',
			{
				type: 'number',
				step: '1.00',
				value: inputValue,
				onChange: handleInputChange,
				placeholder: i18n.amount,
			}
		);
	}

	function MerchantPriceInspectorControls( props ) {
		const {
			attributes: {
				title  = '',
				amount = '100.00'
			},
			setAttributes
		}              = props;

		return createElement(
			PanelBody,
			{
				title: i18n.invoice
			},
			createElement(
				PanelRow,
				{},
				createElement(
					'label',
					{},
					createElement(
						'span',
						{},
						i18n.title
					),
					createElement(
						TitleInput,
						{
							value: title,
							onUpdate: (newTitle) => setAttributes(
								{
									title: newTitle
								}
							),
						}
					)
				),
			),
			createElement(
				PanelRow,
				{},
				createElement(
					'label',
					{},
					createElement(
						'span',
						{},
						i18n.price
					),
					createElement(
						AmountInput,
						{
							value: amount,
							onUpdate: (newAmount) => {
								setAttributes(
									{
										amount: newAmount
									}
								);
							},
						}
					)
				)
			)
		);
	}

	function MerchantPriceEditComponent(props) {
		const {
			attributes: {
				title                = '',
				amount               = '100.00'
			},
			setAttributes
		}                            = props;
		const { openGeneralSidebar } = useDispatch( 'core/edit-post' );

		return createElement(
			'div',
			{
				onClick: () => {
					openGeneralSidebar( 'edit-post/block' );
				}
			},
			createElement(
				InspectorControls,
				{},
				createElement(
					MerchantPriceInspectorControls,
					props
				)
			),
			createElement(
				ServerSideRenderWithCallback,
				{
					block: 'piwa/merchant-price',
					attributes: props.attributes,
					onRender: function ( button_wrapper ) {
						let button = button_wrapper.querySelector( 'figure div.amazon-pay:not(.amazon-pay-initialized)' );
						if ( ! button ) {
							return;
						}
						let id = unique_id( button );
						clearTimeout( window.PIWAButtonRenderTimeouts[ id ] );
						window.PIWAButtonRenderTimeouts[ id ] = setTimeout(
							function () {
								window.maybe_render_amazon_buttons( button_wrapper );
							},
							700
						)
					}
				}
			)
		);
	}

	function ShowCustomerInvoiceInputCheckbox( props ) {
		const {
			checked,
			onUpdate
		} = props;

		return createElement(
			'input',
			{
				type: 'checkbox',
				checked: checked,
				onChange: ( event ) => {
					onUpdate( event.target.checked );
				}
			}
		);
	}

	function CustomerPriceInspectorControls( props ) {
		const {
			attributes: {
				show_customer_invoice_input
			},
			setAttributes
		} = props;

		return createElement(
			PanelBody,
			{
				title: i18n.invoice
			},
			createElement(
				PanelRow,
				{
					className: 'amazon-pay-input-label'
				},
				createElement(
					'label',
					{},
					createElement(
						ShowCustomerInvoiceInputCheckbox,
						{
							checked: show_customer_invoice_input,
							onUpdate: ( show_customer_invoice_value ) => {
								setAttributes(
									{
										show_customer_invoice_input: Boolean( show_customer_invoice_value )
									}
								);
							},
						}
					),
					createElement(
						'span',
						{},
						i18n.show_customer_invoice_input_label
					),
				)
			),
			createElement(
				PanelRow,
				{},
				createElement(
					'p',
					{
						className: 'components-base-control__help amazon-pay-input-help'
					},
					i18n.customer_input_help_text
				)
			)
		);
	}

	function CustomerPriceEditComponent(props) {
		const {
			attributes: {
				show_customer_invoice_input
			},
			setAttributes
		}                            = props;
		const { openGeneralSidebar } = useDispatch( 'core/edit-post' );

		return createElement(
			'div',
			{
				onClick: () => {
					openGeneralSidebar( 'edit-post/block' );
				}
			},
			createElement(
				InspectorControls,
				{},
				createElement(
					CustomerPriceInspectorControls,
					props
				)
			),
			createElement(
				ServerSideRenderWithCallback,
				{
					block: 'piwa/customer-price',
					attributes: props.attributes,
					onRender: function ( button_wrapper ) {
						let button = button_wrapper.querySelector( 'figure div.amazon-pay:not(.amazon-pay-initialized)' );
						if ( ! button ) {
							return;
						}
						let id = unique_id( button );
						clearTimeout( window.PIWAButtonRenderTimeouts[ id ] );
						window.PIWAButtonRenderTimeouts[ id ] = setTimeout(
							function () {
								window.maybe_render_amazon_buttons( button_wrapper );
							},
							700
						)
					}
				}
			)
		);
	}

	/**
	 * Assures a unique button ID in a block editor context, allowing unsaved buttons to render.
	 *
	 * amazon.Pay.renderButton in amazon-pay-buttons-init.js needs a unique ID.
	 * When multiple blocks are being generated over REST edit callbacks, IDs may not be unique.
	 */
	function unique_id( button ) {
		let current_id = button.getAttribute( 'id' );

		if ( 1 < document.querySelectorAll( 'figure div[id="' + current_id + '"]' ).length ) {
			button.setAttribute( 'id', current_id + '-' + Math.floor( Math.random() * 10000 ) );
		}

		return button.getAttribute( 'id' );
	}

	registerBlockType(
		'piwa/customer-price',
		{
			title: i18n.block_title_customer_price,
			icon: 'amazon',
			category: 'common',

			attributes: {
				show_customer_invoice_input: { type: 'boolean', default: false }
			},

			edit: CustomerPriceEditComponent
		}
	);

	registerBlockType(
		'piwa/merchant-price',
		{
			title: i18n.block_title_merchant_price,
			icon: 'amazon',
			category: 'common',

			attributes: {
				title: { type: 'string' },
				amount: { type: 'string', default: '100.00' }
			},

			edit: MerchantPriceEditComponent
		}
	);
})();

(function () {
	if ( PIWAModal && 0 < Object.keys( PIWAModal ).length ) {

		let modal = new tingle.modal(
			{
				footer: true,
				stickyFooter: false,
				closeMethods: ['overlay', 'button', 'escape'],
				closeLabel: 'Close',
			}
		);
		modal.addFooterBtn(
			PIWAModal.continue,
			'tingle-btn tingle-btn--primary',
			function () {
				modal.close();
			}
		);

		if ( 'paid' === PIWAModal.status || 'pending_authorization' === PIWAModal.status ) {
			if ( 0 !== PIWAModal.invoice_number.length ) {
				PIWAModal.payment.invoice_message = PIWAModal.payment.invoice_message
					.replace( '@invoice_number@', PIWAModal.invoice_number );
			} else {
				//Success, but no invoice number.
				PIWAModal.payment.invoice_message = '';
			}
		}

		let payment_message = '';
		if ( 0 < PIWAModal.title.length ) {
			payment_message = PIWAModal.payment.message_with_title
				.replace( '@currency_symbol@', PIWAModal.currency_symbol )
				.replace( '@amount@', PIWAModal.amount )
				.replace( '@title@', PIWAModal.title );
		} else {
			payment_message = PIWAModal.payment.message
				.replace( '@currency_symbol@', PIWAModal.currency_symbol )
				.replace( '@amount@', PIWAModal.amount )
				.replace( '@title@', PIWAModal.title );
		}

		modal.setContent(
			`
			<div>
				${PIWAModal.payment.icon}
				<h4> ${PIWAModal.payment.title} </h4>
				<p> ${payment_message} </p>
				${ PIWAModal.payment.invoice_message ? ` <p> ${PIWAModal.payment.invoice_message} </p> ` : '' }
			</div>
			`
		);

		modal.open();
	}
})();

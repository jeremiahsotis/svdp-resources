<div>
	<h3>
		<?php esc_html_e('License', 'wp-google-maps'); ?>
	</h3>

    <fieldset data-license-field-template style='margin-bottom: 10px;'>
		<legend class='title'></legend>

		<input name="" id='' style='width: 400px; margin-right: 5px;' />
        <small data-license-field-status></small>
	</fieldset>

    <div data-license-field-wrapper></div>

	<fieldset>
		<legend class='title'></legend>
		<span class="settings-group">
			<p>
				<small>
					<strong><?php esc_html_e("Where are my license keys?", "wp-google-maps"); ?></strong>
					<?php echo sprintf( __( "Find your licenses in your <a href='%s' target='_BLANK'>account area</a> or by contact us.", "wp-google-maps" ), "https://www.wpgmaps.com/my-account" ); ?>		
				</small>
			</p>
		</span>
	</fieldset>
</div>
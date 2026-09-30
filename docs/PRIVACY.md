# Privacy

The plugin stores connection configuration, selected post content in publication snapshots, status, and an audit record with administrator user IDs. The destination receives the selected post title, URL, rendered text, content profile, objective, source type, and source ID. A webhook receives the signed JSON payload; Telegram receives rendered text and the configured channel/group identifier.

Do not map sensitive custom fields or select private content for a recipe. This build only watches public post types moving to `publish`; administrators should still review templates and destination access. Private-recipient messaging and subscriber audiences are unavailable. Data is preserved on uninstall unless the explicit removal setting is enabled. There is no automatic retention or WordPress privacy exporter/eraser integration yet. Site owners should account for the job and audit tables in their own retention/export processes.

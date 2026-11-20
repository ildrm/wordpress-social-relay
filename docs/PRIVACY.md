# Privacy

Social Relay stores destination configuration and encrypted credentials, content snapshots for eligible posts, delivery outcomes, and audit entries with administrator user IDs. Snapshots can include the post title, permalink, rendered text, featured image URL and alt text, and the rules evaluated for that publication. Mapped fields and WordPress author names can appear in the rendered text when you include their template variables.

Each network receives the fields required for its publishing operation and the credential used to authorize it. Instagram and Pinterest download the public featured image. Signed webhooks receive the rendered message and structured source information. WhatsApp receives the message when the channel operator pastes and publishes it. See the [destination matrix](providers/README.md) for formats.

Publishing content uses the account and terms of each provider. In **Overview ? Publishing settings**, you can opt into deleting stored plugin data when the plugin is uninstalled. Use the provider?s own controls to remove a remote publication.

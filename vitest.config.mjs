import { fileURLToPath } from 'node:url';

import { defineConfig } from 'vite';

// 本体が単独のスクリプトとして配り、npm からは入れていないモジュール。テストでは描く部品の外側だけを使う。
const stub = fileURLToPath(
	new URL( './tests/js/wp-stubs.js', import.meta.url )
);

export default defineConfig( {
	// src の .js は JSX を含む。ビルドと同じく自動の JSX 変換で読む。
	oxc: {
		include: /\.[jt]sx?$/,
		exclude: [],
		lang: 'jsx',
		jsx: { runtime: 'automatic' },
	},
	resolve: {
		alias: {
			'@wordpress/core-data': stub,
			'@wordpress/editor': stub,
			'@wordpress/notices': stub,
			'@wordpress/preferences': stub,
		},
	},
} );

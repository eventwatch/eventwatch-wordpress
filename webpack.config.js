const [
	scriptConfig,
	...moduleConfigs
] = require( '@wordpress/scripts/config/webpack.config' );

// src に block.json があると、既定の入口は block.json が指すものだけになる。編集画面のパネルの入口を足す。
module.exports = [
	{
		...scriptConfig,
		entry: () => ( {
			...scriptConfig.entry(),
			index: './src/index.js',
			'import-page': './src/import-page/index.js',
		} ),
	},
	...moduleConfigs,
];

# Automated fix for issue #48: 編集画面の住所・日程の補足・オンラインの欄に、アクセシブルな名前が付いていない

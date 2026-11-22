// メニューは role="menu" の作法に従う。開くと最初の項目へ、上下で移動、Esc で閉じてボタンへ戻す。
function setupMenu( root ) {
	const trigger = root.querySelector( '[data-eventwatch-menu-trigger]' );
	const menu = root.querySelector( '[role="menu"]' );
	if ( ! trigger || ! menu ) {
		return null;
	}

	const items = () =>
		Array.from( menu.querySelectorAll( '[role="menuitem"]' ) );

	// JS が無いときはリンクをそのまま Tab で辿れるよう、ここで外す。項目の移動は矢印で行う。
	items().forEach( ( item ) => item.setAttribute( 'tabindex', '-1' ) );

	const close = ( returnFocus ) => {
		if ( menu.hidden ) {
			return;
		}
		menu.hidden = true;
		trigger.setAttribute( 'aria-expanded', 'false' );
		if ( returnFocus ) {
			trigger.focus();
		}
	};

	const open = () => {
		menu.hidden = false;
		trigger.setAttribute( 'aria-expanded', 'true' );
		items()[ 0 ]?.focus();
	};

	menu.addEventListener( 'keydown', ( event ) => {
		const list = items();
		const index = list.indexOf( event.target.ownerDocument.activeElement );
		let next = null;
		if ( event.key === 'ArrowDown' ) {
			next = list[ ( index + 1 ) % list.length ];
		} else if ( event.key === 'ArrowUp' ) {
			next = list[ ( index - 1 + list.length ) % list.length ];
		} else if ( event.key === 'Home' ) {
			next = list[ 0 ];
		} else if ( event.key === 'End' ) {
			next = list[ list.length - 1 ];
		} else if ( event.key === 'Escape' ) {
			event.preventDefault();
			close( true );
			return;
		} else if ( event.key === 'Tab' ) {
			close( false );
			return;
		}
		if ( next ) {
			event.preventDefault();
			next.focus();
		}
	} );

	// 項目を押したら閉じる。新しいタブから戻ったときに開いたまま残さない。
	menu.addEventListener( 'click', () => close( true ) );

	root.ownerDocument.addEventListener( 'click', ( event ) => {
		if ( ! root.contains( event.target ) ) {
			close( false );
		}
	} );

	return { trigger, menu, open, close };
}

async function copyText( text ) {
	await window.navigator.clipboard.writeText( text );
}

function setupShare( root, control ) {
	const { url, title, copied, copyFailed } = root.dataset;
	const status = root.parentElement.querySelector(
		'[data-eventwatch-status]'
	);

	const show = ( text ) => {
		if ( status ) {
			status.textContent = text;
		}
	};

	control.trigger.addEventListener( 'click', async () => {
		if ( ! control.menu.hidden ) {
			control.close( false );
			return;
		}
		if ( typeof window.navigator.share !== 'function' ) {
			control.open();
			return;
		}
		try {
			await window.navigator.share( { title, url } );
		} catch ( error ) {
			// 利用者が閉じたときは何もしない。共有できなかったときは、メニューで代わりの手段を出す。
			if ( error?.name !== 'AbortError' ) {
				control.open();
			}
		}
	} );

	root.querySelector( '[data-eventwatch-copy]' )?.addEventListener(
		'click',
		async () => {
			try {
				await copyText( url );
				show( copied );
			} catch {
				show( copyFailed );
			}
		}
	);
}

function setupDialog( opener ) {
	const dialog = opener.ownerDocument.getElementById(
		opener.dataset.eventwatchDialogOpen
	);
	if ( ! dialog ) {
		return;
	}

	opener.addEventListener( 'click', () => {
		// フォーカスの閉じ込め・Esc・背後の操作の遮断・閉じた後のフォーカスの戻りは <dialog> が持つ。
		dialog.showModal();
		dialog.querySelector( 'h2' )?.focus();
	} );

	dialog
		.querySelector( '[data-eventwatch-dialog-close]' )
		?.addEventListener( 'click', () => dialog.close() );

	// 幕を押したら閉じる。中の余白を押したときの対象は子の要素ではなく dialog なので、位置で見分ける。
	dialog.addEventListener( 'click', ( event ) => {
		if ( event.target !== dialog ) {
			return;
		}
		const box = dialog.getBoundingClientRect();
		const inside =
			event.clientX >= box.left &&
			event.clientX <= box.right &&
			event.clientY >= box.top &&
			event.clientY <= box.bottom;
		if ( ! inside ) {
			dialog.close();
		}
	} );
}

// JS が動かないときの CSS（Event_Detail::no_script_css）を外す印。head のインラインが CSP などで止まっても、
// このスクリプトが動いてメニューの項目を tabindex="-1" にするなら、メニューの形に切り替えないとリンクへ Tab で届かない。
document.documentElement.classList.add( 'eventwatch-js' );

document.querySelectorAll( '[data-eventwatch-menu]' ).forEach( ( root ) => {
	const control = setupMenu( root );
	if ( ! control ) {
		return;
	}
	if ( root.hasAttribute( 'data-eventwatch-share' ) ) {
		setupShare( root, control );
		return;
	}
	control.trigger.addEventListener( 'click', () => {
		if ( control.menu.hidden ) {
			control.open();
		} else {
			control.close( false );
		}
	} );
} );

document
	.querySelectorAll( '[data-eventwatch-dialog-open]' )
	.forEach( setupDialog );

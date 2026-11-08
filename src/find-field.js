/**
 * 移動先の名前から、編集画面の欄の要素を引く。
 *
 * TextareaControl と ToggleControl は id を受け取らず、自分で作る id でラベルを結ぶ。
 * id を渡すと欄の id だけが置き換わり、ラベルの for が指す要素が無くなる。
 * そのためこの 2 つの欄では、移動先の名前を data-eventwatch-field に置く。
 *
 * @param {string} name 欄の id、または data-eventwatch-field の値。
 * @return {HTMLElement|null} 欄の要素。無ければ null。
 */
const findField = ( name ) =>
	document.getElementById( name ) ??
	Array.from( document.querySelectorAll( '[data-eventwatch-field]' ) ).find(
		( element ) => element.dataset.eventwatchField === name
	) ??
	null;

export default findField;

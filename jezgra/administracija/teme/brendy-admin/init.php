<?php defined('BRENDY') or die('Brendy CMS.');

class BrendyAdminUi
{
	private static function raw($value)
	{
		return is_scalar($value) ? (string)$value : '';
	}

	// Values in legacy databases may already be entity encoded. Decode one
	// storage layer and encode exactly once for the current HTML context.
	private static function escape($value)
	{
		$flags = ENT_QUOTES | ENT_SUBSTITUTE;
		if (defined('ENT_HTML5')) $flags |= ENT_HTML5;
		$decoded = html_entity_decode(self::raw($value), $flags, CHARSET);
		return htmlspecialchars($decoded, $flags, CHARSET);
	}

	private static function idFromName($name)
	{
		$id = preg_replace('/[^A-Za-z0-9_-]+/', '-', self::raw($name));
		$id = trim((string)$id, '-');
		return 'js' . ($id === '' ? 'field' : $id);
	}

	private static function icon($name)
	{
		$name = self::raw($name);
		if (strpos($name, 'bi-') === 0 && preg_match('/^bi-[A-Za-z0-9-]{1,60}$/D', $name) === 1) return $name;
		$icons = array(
			'archive' => 'bi-archive', 'arrows' => 'bi-arrows-move', 'box' => 'bi-box-seam',
			'cog' => 'bi-gear', 'desktop' => 'bi-display', 'exchange' => 'bi-arrow-left-right',
			'gears' => 'bi-gear-wide-connected', 'heartbeat' => 'bi-heart',
			'info-circle' => 'bi-info-circle', 'plus' => 'bi-plus-circle',
			'puzzle-piece' => 'bi-puzzle', 'tag' => 'bi-tag', 'tags' => 'bi-tags',
			'user' => 'bi-person', 'users' => 'bi-people', 'file' => 'bi-file-earmark-text'
		);
		return isset($icons[$name]) ? $icons[$name] : 'bi-circle';
	}

	private static function tip($args)
	{
		$text = isset($args['tip']) ? self::escape($args['tip']) : '';
		$codes = '';
		if (isset($args['tipCode']) && is_array($args['tipCode'])) {
			foreach (array_slice($args['tipCode'], 0, 20) as $code) {
				$codes .= ' <code>' . self::escape($code) . '</code>';
			}
		}
		if ($text === '' && $codes === '') return '';
		return '<small class="form-text text-muted">' . $text . $codes . '</small>';
	}

	public static function modal($args)
	{
		$args = is_array($args) ? $args : array();
		$buttonSecondary = self::escape(isset($args['buttonSecondary']) ? $args['buttonSecondary'] : '');
		$buttonSecondaryClass = self::escape(isset($args['buttonSecondaryClass']) ? $args['buttonSecondaryClass'] : 'btn-secondary');
		$buttonPrimary = self::escape(isset($args['buttonPrimary']) ? $args['buttonPrimary'] : '');
		$buttonPrimaryClass = self::escape(isset($args['buttonPrimaryClass']) ? $args['buttonPrimaryClass'] : 'btn-primary');
		$modalText = self::escape(isset($args['modalText']) ? $args['modalText'] : '');
		$modalTitle = self::escape(isset($args['modalTitle']) ? $args['modalTitle'] : '');
		$modalId = self::escape(isset($args['modalId']) ? $args['modalId'] : 'jsmodal');

		return <<<EOF
<div id="$modalId" class="modal fade" tabindex="-1" role="dialog">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-body">
				<h3>$modalTitle</h3>
				<p>$modalText</p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn $buttonSecondaryClass" data-bs-dismiss="modal">$buttonSecondary</button>
				<button type="button" class="btn $buttonPrimaryClass">$buttonPrimary</button>
			</div>
		</div>
	</div>
</div>
EOF;
	}

	public static function link($args)
	{
		$args = is_array($args) ? $args : array();
		$href = self::escape(isset($args['href']) ? $args['href'] : '#');
		$options = 'href="' . $href . '"';
		if (isset($args['class'])) $options .= ' class="' . self::escape($args['class']) . '"';
		if (isset($args['target']) && in_array($args['target'], array('_blank', '_self', '_parent', '_top'), true)) {
			$options .= ' target="' . $args['target'] . '"';
			if ($args['target'] === '_blank') $options .= ' rel="noopener noreferrer"';
		}
		$title = self::escape(isset($args['title']) ? $args['title'] : '');
		if (isset($args['icon']) && preg_match('/^[A-Za-z0-9-]{1,50}$/D', self::raw($args['icon'])) === 1) {
			return '<a ' . $options . '><i class="bi ' . self::icon($args['icon']) . '" aria-hidden="true"></i>' . $title . '</a>';
		}
		return '<a ' . $options . '>' . $title . '</a>';
	}

	public static function pageTitle($args)
	{
		$args = is_array($args) ? $args : array();
		$icon = isset($args['icon']) && preg_match('/^[A-Za-z0-9-]{1,50}$/D', self::raw($args['icon'])) === 1
			? $args['icon'] : 'file';
		$title = self::escape(isset($args['title']) ? $args['title'] : '');
		return '<h2 class="mt-0 mb-3 brendy-page-title"><i class="bi ' . self::icon($icon) . '" aria-hidden="true"></i><span>' . $title . '</span></h2>';
	}

	public static function formOpen($args)
	{
		$args = is_array($args) ? $args : array();
		$class = isset($args['class']) && self::raw($args['class']) !== '' ? 'class="' . self::escape($args['class']) . '"' : '';
		$id = isset($args['id']) && self::raw($args['id']) !== '' ? 'id="' . self::escape($args['id']) . '"' : '';
		$enctype = isset($args['enctype']) && $args['enctype'] === 'multipart/form-data'
			? 'enctype="multipart/form-data"' : '';
		$action = 'action="' . self::escape(isset($args['action']) ? $args['action'] : '') . '"';
		$methodValue = isset($args['method']) && strtolower(self::raw($args['method'])) === 'get' ? 'get' : 'post';
		$method = 'method="' . $methodValue . '"';
		$style = isset($args['style']) && self::raw($args['style']) !== '' ? 'style="' . self::escape($args['style']) . '"' : '';
		$autocomplete = isset($args['autocomplete']) && in_array($args['autocomplete'], array('on', 'off'), true)
			? 'autocomplete="' . $args['autocomplete'] . '"' : '';
		return "<form $class $enctype $id $method $action $style $autocomplete>";
	}

	public static function formClose()
	{
		return '</form>';
	}

	public static function formTitle($args)
	{
		$title = self::escape(is_array($args) && isset($args['title']) ? $args['title'] : '');
		return '<h6 class="mt-4 mb-2 pb-2 border-bottom text-uppercase">' . $title . '</h6>';
	}

	public static function formInputTextBlock($args)
	{
		$args = is_array($args) ? $args : array();
		$nameRaw = isset($args['name']) ? self::raw($args['name']) : '';
		$name = self::escape($nameRaw);
		$id = self::escape(isset($args['id']) ? $args['id'] : self::idFromName($nameRaw));
		$class = self::escape(isset($args['class']) ? $args['class'] : 'form-group m-0');
		$labelClass = self::escape(isset($args['labelClass']) ? $args['labelClass'] : 'mt-4 mb-2 pb-2 border-bottom text-uppercase w-100');
		$label = isset($args['label']) && self::raw($args['label']) !== '' ? '<label class="' . $labelClass . '" for="' . $id . '">' . self::escape($args['label']) . '</label>' : '';
		$value = self::escape(isset($args['value']) ? $args['value'] : '');
		$placeholder = self::escape(isset($args['placeholder']) ? $args['placeholder'] : '');
		$disabled = !empty($args['disabled']) ? 'disabled' : '';
		$tip = self::tip($args);
		return '<div class="' . $class . '">' . $label . '<input type="text" dir="auto" value="' . $value .
			'" class="form-control" id="' . $id . '" name="' . $name . '" placeholder="' . $placeholder . '" ' . $disabled . '>' . $tip . '</div>';
	}

	public static function formInputDateTime($args)
	{
		$args = is_array($args) ? $args : array();
		$value = Date::fromLocalInput($args['value'] ?? '');
		$args['value'] = $value === false ? '' : str_replace(' ', 'T', $value);
		// Reuse the escaped label, ID and layout of the existing input helper.
		return str_replace('<input type="text" dir="auto"', '<input type="datetime-local" step="1" min="0001-01-01T00:00" max="9999-12-31T23:59:59" required', self::formInputTextBlock($args));
	}

	public static function formInputFile($args)
	{
		$args = is_array($args) ? $args : array();
		$nameRaw = isset($args['name']) ? self::raw($args['name']) : 'file';
		$id = self::escape(isset($args['id']) ? $args['id'] : self::idFromName($nameRaw));
		$class = self::escape('mb-3' . (isset($args['class']) ? ' ' . self::raw($args['class']) : ''));
		$label = self::escape(isset($args['label']) ? $args['label'] : '');
		return '<div class="' . $class . '"><label class="form-label" for="' . $id . '">' . $label . '</label><input type="file" class="form-control" id="' . $id . '" name="' . self::escape($nameRaw) . '"></div>';
	}

	public static function formTextarea($args)
	{
		$args = is_array($args) ? $args : array();
		$nameRaw = isset($args['name']) ? self::raw($args['name']) : '';
		$name = self::escape($nameRaw);
		$id = self::escape(isset($args['id']) ? $args['id'] : self::idFromName($nameRaw));
		$class = self::escape('form-control' . (isset($args['class']) ? ' ' . self::raw($args['class']) : ''));
		$rows = isset($args['rows']) ? max(1, min(1000, (int)$args['rows'])) : 4;
		$label = isset($args['label']) && self::raw($args['label']) !== '' ? '<label for="' . $id . '" class="col-sm-2 col-form-label">' . self::escape($args['label']) . '</label>' : '';
		$placeholder = self::escape(isset($args['placeholder']) ? $args['placeholder'] : '');
		$value = self::escape(isset($args['value']) ? $args['value'] : '');
		return '<div class="form-group row">' . $label . '<div class="col-sm-10"><textarea class="' . $class . '" id="' . $id .
			'" name="' . $name . '" rows="' . $rows . '" placeholder="' . $placeholder . '">' . $value . '</textarea>' . self::tip($args) . '</div></div>';
	}

	public static function formTextareaBlock($args)
	{
		$args = is_array($args) ? $args : array();
		$nameRaw = isset($args['name']) ? self::raw($args['name']) : '';
		$name = self::escape($nameRaw);
		$id = self::escape(isset($args['id']) ? $args['id'] : self::idFromName($nameRaw));
		$class = self::escape('form-control' . (!empty($args['class']) ? ' ' . self::raw($args['class']) : ''));
		$rows = isset($args['rows']) ? max(1, min(1000, (int)$args['rows'])) : 4;
		$label = isset($args['label']) && self::raw($args['label']) !== '' ? '<label class="mt-4 mb-2 pb-2 border-bottom text-uppercase w-100" for="' . $id . '">' . self::escape($args['label']) . '</label>' : '';
		$placeholder = self::escape(isset($args['placeholder']) ? $args['placeholder'] : '');
		$value = self::escape(isset($args['value']) ? $args['value'] : '');
		return '<div class="form-group m-0">' . $label . '<textarea class="' . $class . '" id="' . $id . '" name="' . $name .
			'" rows="' . $rows . '" placeholder="' . $placeholder . '">' . $value . '</textarea>' . self::tip($args) . '</div>';
	}

	public static function formInputText($args)
	{
		$args = is_array($args) ? $args : array();
		$nameRaw = isset($args['name']) ? self::raw($args['name']) : '';
		$name = self::escape($nameRaw);
		$id = self::escape(isset($args['id']) ? $args['id'] : self::idFromName($nameRaw));
		$class = self::escape('form-control' . (isset($args['class']) ? ' ' . self::raw($args['class']) : ''));
		$type = isset($args['type']) && in_array($args['type'], array('text', 'password', 'email', 'url', 'number'), true) ? $args['type'] : 'text';
		$label = isset($args['label']) && self::raw($args['label']) !== '' ? '<label for="' . $id . '" class="col-sm-2 col-form-label">' . self::escape($args['label']) . '</label>' : '';
		$value = self::escape(isset($args['value']) ? $args['value'] : '');
		$placeholder = self::escape(isset($args['placeholder']) ? $args['placeholder'] : '');
		$disabled = !empty($args['disabled']) ? 'disabled' : '';
		$readonly = !empty($args['readonly']) ? 'readonly' : '';
		$required = !empty($args['required']) ? 'required' : '';
		$minlength = isset($args['minlength']) ? max(0, min(1000000, (int)$args['minlength'])) : 0;
		$maxlength = isset($args['maxlength']) ? max(0, min(1000000, (int)$args['maxlength'])) : 0;
		$lengthAttributes = ($minlength > 0 ? ' minlength="' . $minlength . '"' : '') .
			($maxlength > 0 ? ' maxlength="' . $maxlength . '"' : '');
		$autocomplete = isset($args['autocomplete']) && in_array($args['autocomplete'],
			array('on', 'off', 'username', 'email', 'current-password', 'new-password'), true)
			? ' autocomplete="' . self::escape($args['autocomplete']) . '"' : '';
		$numberAttributes = '';
		if ($type === 'number') {
			foreach (array('min', 'max', 'step') as $attribute) {
				if (!isset($args[$attribute])) continue;
				$valueRaw = self::raw($args[$attribute]);
				if ($attribute === 'step' && $valueRaw === 'any') $numberAttributes .= ' step="any"';
				elseif (preg_match('/^-?(?:\d+|\d*\.\d+)$/D', $valueRaw) === 1) $numberAttributes .= ' ' . $attribute . '="' . self::escape($valueRaw) . '"';
			}
		}
		$inputmode = isset($args['inputmode']) && in_array($args['inputmode'], array('none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'), true)
			? ' inputmode="' . $args['inputmode'] . '"' : '';
		return '<div class="form-group row">' . $label . '<div class="col-sm-10"><input class="' . $class . '" id="' . $id . '" name="' . $name .
			'" value="' . $value . '" placeholder="' . $placeholder . '" type="' . $type . '" dir="auto"' . $lengthAttributes .
			$autocomplete . $numberAttributes . $inputmode . ' ' . $disabled . ' ' . $readonly . ' ' . $required . '>' .
			self::tip($args) . '</div></div>';
	}

	public static function formCheckbox($args)
	{
		$args = is_array($args) ? $args : array();
		$nameRaw = isset($args['name']) ? self::raw($args['name']) : '';
		$name = self::escape($nameRaw);
		$id = self::escape(isset($args['id']) ? $args['id'] : self::idFromName($nameRaw));
		$class = self::escape(isset($args['class']) ? $args['class'] : 'form-group m-0');
		$labelClass = self::escape(isset($args['labelClass']) ? $args['labelClass'] : 'mt-4 mb-2 pb-2 border-bottom text-uppercase w-100');
		$label = isset($args['label']) && self::raw($args['label']) !== '' ? '<div class="' . $labelClass . '">' . self::escape($args['label']) . '</div>' : '';
		$checked = function_exists('brendyBooleanValue') ? brendyBooleanValue(isset($args['checked']) ? $args['checked'] : false, false) === true : (isset($args['checked']) && $args['checked'] === true);
		$disabled = !empty($args['disabled']);
		// Standard hidden+checkbox semantics keep boolean forms functional even if
		// administrator JavaScript is blocked. Disabled controls preserve their
		// current value because the checkbox itself is not submitted by browsers.
		$hiddenValue = $disabled ? ($checked ? '1' : '0') : '0';
		$disabledAttr = $disabled ? 'disabled' : '';
		$labelForCheckbox = self::escape(isset($args['labelForCheckbox']) ? $args['labelForCheckbox'] : '');
		return '<div class="' . $class . '">' . $label . '<div class="form-check"><input type="hidden" name="' . $name . '" value="' . $hiddenValue .
			'"><input id="' . $id . '" name="' . $name . '" value="1" type="checkbox" class="form-check-input" data-brendy-sync-hidden="true" ' .
			($checked ? 'checked' : '') . ' ' . $disabledAttr . '><label class="form-check-label" for="' . $id . '">' . $labelForCheckbox . '</label>' .
			self::tip($args) . '</div></div>';
	}

	/** Shared renderer for custom fields in new/edit content views. */
	public static function contentCustomFields($customFields, $position = 'tab', $page = null)
	{
		if (!is_array($customFields) || !in_array($position, array('tab', 'top', 'bottom'), true)) return '';
		$html = '';
		$count = 0;
		foreach ($customFields as $field => $options) {
			if (++$count > 500 || !is_string($field) ||
				preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $field) !== 1 || !is_array($options)) continue;
			$configuredPosition = isset($options['position']) && is_string($options['position']) ? $options['position'] : null;
			if (($position === 'tab' && $configuredPosition !== null) ||
				($position !== 'tab' && $configuredPosition !== $position)) continue;
			$type = isset($options['type']) && is_string($options['type']) ? $options['type'] : '';
			$name = 'custom[' . $field . ']';
			$common = array(
				'name' => $name,
				'label' => isset($options['label']) && is_scalar($options['label']) ? (string)$options['label'] : '',
				'placeholder' => isset($options['placeholder']) && is_scalar($options['placeholder']) ? (string)$options['placeholder'] : ''
			);
			if ($position === 'top') {
				$common['class'] = 'mb-2';
				$common['labelClass'] = 'mb-2 pb-2 border-bottom text-uppercase w-100';
			} elseif ($position === 'bottom') {
				$common['class'] = 'mt-2';
				$common['labelClass'] = 'mb-2 pb-2 border-bottom text-uppercase w-100';
			}

			if ($type === 'string') {
				$value = $page instanceof Page ? $page->custom($field) : (isset($options['default']) && is_scalar($options['default']) ? (string)$options['default'] : '');
				$common['value'] = is_scalar($value) ? (string)$value : '';
				$common['tip'] = isset($options['tip']) && is_scalar($options['tip']) ? (string)$options['tip'] : '';
				$html .= self::formInputTextBlock($common);
			} elseif ($type === 'bool') {
				$value = $page instanceof Page ? $page->custom($field) : (array_key_exists('checked', $options) ? $options['checked'] : false);
				$common['checked'] = function_exists('brendyBooleanValue') ? brendyBooleanValue($value, false) === true : $value === true;
				$common['labelForCheckbox'] = isset($options['tip']) && is_scalar($options['tip']) ? (string)$options['tip'] : '';
				$html .= self::formCheckbox($common);
			}
		}
		return $html;
	}

	/** Shared SEO controls for both new-content and edit-content forms. */
	public static function contentSeoFields($values = array())
	{
		global $L;
		$values = is_array($values) ? $values : array();
		$slug = isset($values['slug']) && is_scalar($values['slug']) ? (string)$values['slug'] : '';
		$html = self::formInputTextBlock(array(
			'name' => 'slug',
			'tip' => $L->g('URL associated with the content'),
			'label' => $L->g('Friendly URL'),
			'placeholder' => $L->g('Leave empty for autocomplete by Brendy.'),
			'value' => $slug
		));
		$robots = array(
			'noindex' => array('apply-code-noindex-code-to-this-page', 'This tells search engines not to show this page in their search results.'),
			'nofollow' => array('apply-code-nofollow-code-to-this-page', 'This tells search engines not to follow links on this page.'),
			'noarchive' => array('apply-code-noarchive-code-to-this-page', 'This tells search engines not to save a cached copy of this page.')
		);
		$first = true;
		foreach ($robots as $field => $labels) {
			$html .= self::formCheckbox(array(
				'name' => $field,
				'label' => $first ? 'Robots' : '',
				'labelForCheckbox' => $L->g($labels[0]),
				'placeholder' => '',
				'checked' => function_exists('brendyBooleanValue') ? brendyBooleanValue(isset($values[$field]) ? $values[$field] : false, false) === true : !empty($values[$field]),
				'tip' => $L->g($labels[1])
			));
			$first = false;
		}
		return $html;
	}

	private static function select($args, $block)
	{
		$args = is_array($args) ? $args : array();
		$nameRaw = isset($args['name']) ? self::raw($args['name']) : '';
		$name = self::escape($nameRaw);
		$id = self::escape(isset($args['id']) ? $args['id'] : self::idFromName($nameRaw));
		$class = self::escape('form-select' . (!empty($args['class']) ? ' ' . self::raw($args['class']) : ''));
		$labelClass = $block ? 'mt-4 mb-2 pb-2 border-bottom text-uppercase w-100' : 'col-sm-2 col-form-label';
		$label = isset($args['label']) && $args['label'] !== '' ? '<label class="' . $labelClass . '" for="' . $id . '">' . self::escape($args['label']) . '</label>' : '';
		$html = $block ? '<div class="form-group m-0">' . $label : '<div class="form-group row">' . $label . '<div class="col-sm-10">';
		$html .= '<select id="' . $id . '" name="' . $name . '" class="' . $class . '">';
		if (isset($args['emptyOption']) && self::raw($args['emptyOption']) !== '') $html .= '<option value="">' . self::escape($args['emptyOption']) . '</option>';
		$options = isset($args['options']) && is_array($args['options']) ? array_slice($args['options'], 0, 10000, true) : array();
		$selected = isset($args['selected']) ? self::raw($args['selected']) : '';
		foreach ($options as $key => $value) {
			$html .= '<option ' . (self::raw($key) === $selected ? 'selected' : '') . ' value="' . self::escape($key) . '">' . self::escape($value) . '</option>';
		}
		$html .= '</select>' . self::tip($args);
		return $html . ($block ? '</div>' : '</div></div>');
	}

	public static function formSelect($args)
	{
		return self::select($args, false);
	}

	public static function formSelectBlock($args)
	{
		return self::select($args, true);
	}

	public static function formInputHidden($args)
	{
		$args = is_array($args) ? $args : array();
		$nameRaw = isset($args['name']) ? self::raw($args['name']) : '';
		return '<input type="hidden" id="' . self::escape(self::idFromName($nameRaw)) . '" name="' . self::escape($nameRaw) .
			'" value="' . self::escape(isset($args['value']) ? $args['value'] : '') . '">';
	}

	public static function alert($args)
	{
		$args = is_array($args) ? $args : array();
		$class = self::escape('alert' . (!empty($args['class']) ? ' ' . self::raw($args['class']) : ''));
		$text = self::escape(isset($args['text']) ? $args['text'] : '');
		return '<div class="' . $class . '" role="alert">' . $text . '</div>';
	}
}

// Compatibility for third-party administration views created for the earlier
// helper class name. Brendy views use BrendyAdminUi directly.
if (!class_exists('Bootstrap', false)) class_alias(BrendyAdminUi::class, 'Bootstrap');

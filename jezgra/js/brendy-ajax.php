class brendyAjax {

	static async saveAsDraft(uuid, title, content, contentVersion = "") {
		const url = HTML_PATH_ADMIN_ROOT + "ajax/save-as-draft";
		try {
			const response = await fetch(url, {
				credentials: 'same-origin',
				method: "POST",
				headers: new Headers({
					'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
					'Accept': 'application/json'
				}),
				body: new URLSearchParams({
					'tokenCSRF': tokenCSRF,
					'uuid': "autosave-" + uuid,
					'title': title,
					'content': content,
					'contentVersion': (typeof contentVersion === 'string' ? contentVersion : ''),
					'type': 'autosave'
				}),
			});
			if (!response.ok) throw new Error("Autosave failed with HTTP " + response.status);
			return await response.json();
		}
		catch (err) {
			return {status: 1, message: "Autosave failed.", networkError: true};
		}
	}

	static async removeLogo() {
		let url = HTML_PATH_ADMIN_ROOT+"ajax/logo-remove"
		try {
			const response = await fetch(url, {
				credentials: 'same-origin',
				method: "POST",
				headers: new Headers({
					'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
				}),
				body: new URLSearchParams({
					'tokenCSRF': tokenCSRF
				}),
			});
			const json = await response.json();
			return json;
		}
		catch (err) {
			return {status: 1, message: "Logo removal failed."};
		}
	}

	generateSlug(text, parentKey, currentKey, callBack) {
		$.ajax({
			type: "POST",
			data: {
				tokenCSRF: tokenCSRF,
				text: text,
				parentKey: parentKey,
				currentKey: currentKey
			},
			url: HTML_PATH_ADMIN_ROOT + "ajax/generate-slug"
		}).done(function (response) {
			if (response && response.status === 0 && typeof response.slug === 'string') {
				callBack.val(response.slug);
			}
		});
	}

}

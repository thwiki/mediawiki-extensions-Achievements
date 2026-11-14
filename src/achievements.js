(function ($, mw) {
	$(document).ready(function () {
		var $tooltips = $("a.achievtitle");
		if ($tooltips.length) {
			mw.loader.using("jquery.tipsy", function () {
				$tooltips.tipsy({
					title: function () {
						var n = $("<div></div>");
						if (this.hasAttribute("src")) {
							n.append(
								$("<div></div>")
									.attr("class", "achievimage")
									.append(
										$("<img/>").attr(
											"src",
											this.getAttribute("src")
										)
									)
							);
						}
						n.append(
							$("<div></div>").html(
								this.getAttribute("original-title")
							)
						);
						return n.html();
					},
					gravity: "w",
					html: true,
				});
			});
		}

		var $avatar = $("img.useravatar");
		if ($avatar.length) {
			mw.loader.using("jquery.tipsy", function () {
				$avatar.tipsy({
					title: function () {
						return $("<div></div>")
							.append(
								$("<img/>").attr(
									"src",
									this.getAttribute("src").replace(
										"_m.",
										"_l."
									)
								)
							)
							.html();
					},
					gravity: "s",
					html: true,
				});
			});
		}

		var config = mw.config.get("wgAvatarConfig");

		/**
		 * @param {number} id
		 * @param {string} name
		 * @return {string}
		 */
		var getAvatar = function (id, username) {
			if (id === 0) {
				return config.AnonymousAvatar;
			} else {
				return config.Avatar.replace(/\$\{userid\}/g, id).replace(
					/\$\{username\}/g,
					username
				);
			}
		};

		var $userAvatar = $("#pt-userpage>a");
		if (config && $userAvatar.length) {
			var avatar = (function () {
				var id = mw.user.getId();
				var username = mw.user.getName();
				return getAvatar(id, username);
			})();
			$userAvatar.prepend(
				$("<img>", {
					id: "pt-useravatar",
					class: "useravatar",
					src: avatar,
				})
			);
		}

		if (config && "flow" in mw && "FlowHandlebars" in mw.flow) {
			// patch FlowHandlebars class

			var FlowHandlebars = mw.flow.FlowHandlebars;

			FlowHandlebars.prototype.avatar = function (user) {
				var username = user["name"] || "";
				var id = user["id"] || 0;
				return FlowHandlebars.prototype.html(
					'<img class="useravatar" src="' +
						getAvatar(id, username) +
						'" />'
				);
			};

			FlowHandlebars.prototype.title = function () {
				return FlowHandlebars.prototype.html("");
			};

			Handlebars.registerHelper(
				"avatar",
				FlowHandlebars.prototype.avatar
			);
			Handlebars.registerHelper("title", FlowHandlebars.prototype.title);
		}
	});
})(window.jQuery, mediaWiki);

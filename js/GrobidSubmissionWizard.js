(function () {
	if (typeof pkp === 'undefined' || typeof pkp.eventBus === 'undefined') {
		return;
	}

	var root;
	pkp.eventBus.$on('root:mounted', function (id, component) {
		root = component;

		root.$watch(
			'components.submissionFiles.items',
			(newSubmissionFiles, oldSubmissionFiles) => {
				const newGrobidded = !!newSubmissionFiles.find(
					(file) => file.grobidded,
				);
				const oldGrobidded = !!oldSubmissionFiles.find(
					(file) => file.grobidded,
				);
				if (newGrobidded && !oldGrobidded) {
					$.ajax({
						url: root.publicationApiUrl,
						method: 'GET',
						context: root,
						error: root.ajaxErrorCallback,
						success(r) {
							const {useForm} = pkp.modules.useForm;
							root.setPublication(r);
							// update Details form
							const detailsStep = root.steps.find(
								(step) => step.id === 'details',
							);
							if (detailsStep) {
								const detailsSection = detailsStep.sections.find(
									(section) => section.id === 'titleAbstract',
								);
								if (detailsSection) {
									const {setValues} = useForm(detailsSection.form);
									setValues(r);
								}
							}
						},
					});
				}
			},
		);
	});
})();

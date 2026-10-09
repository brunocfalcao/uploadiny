import AVKit
import ImageIO
import Security
import UIKit
import UniformTypeIdentifiers

final class ShareIntoViewController: UIViewController, UITableViewDataSource, UITableViewDelegate, UICollectionViewDataSource, UICollectionViewDelegate, UITextViewDelegate, URLSessionTaskDelegate {
  private let titleLabel = UILabel()
  private let detailLabel = UILabel()
  private let emptyProjectIcon = UIImageView()
  private let preview = UIImageView()
  private var previewHeight: NSLayoutConstraint!
  private var tableHeight: NSLayoutConstraint!
  private let table = UITableView(frame: .zero, style: .plain)
  private let uploadButton = UIButton(type: .system)
  private let closeButton = UIButton(type: .system)
  private let emailField = UITextField()
  private let passwordField = UITextField()
  private let signInButton = UIButton(type: .system)
  private let signOutButton = UIButton(type: .system)
  private let spinner = UIActivityIndicatorView(style: .medium)
  private let uploadProgress = UIProgressView(progressViewStyle: .default)
  private weak var currentUploadTask: URLSessionTask?
  private var autoCloseTimer: Timer?
  private var uploadTotalBytes: Int64 = 0
  private var uploadDoneBytes: Int64 = 0
  private var currentFileBytes: Int64 = 0
  private var currentFileIndex = 0
  private var projects: [Project] = []
  private var selectedProject: Project?
  private var projectIsRemembered = false
  private var providers: [NSItemProvider] = []
  private var review = ShareReview()
  private let reviewPanel = UIStackView()
  private let assetPreview = UIImageView()
  private var assetPreviewHeight: NSLayoutConstraint!
  private let assetLabel = UILabel()
  private let mediaLabel = UILabel()
  private let feedbackCard = UIView()
  private let feedbackStatus = UILabel()
  private let footerSummary = UILabel()
  private var feedbackHeight: NSLayoutConstraint!
  private let assetStrip: UICollectionView = {
    let layout = UICollectionViewFlowLayout()
    layout.scrollDirection = .horizontal
    layout.itemSize = CGSize(width: 68, height: 68)
    layout.minimumLineSpacing = 10
    layout.sectionInset = UIEdgeInsets(top: 4, left: 2, bottom: 4, right: 2)
    return UICollectionView(frame: .zero, collectionViewLayout: layout)
  }()
  private let assetNameLabel = UILabel()
  private let feedbackField = UITextView()
  private let feedbackPlaceholder = UILabel()
  private let previousButton = UIButton(type: .system)
  private let nextButton = UIButton(type: .system)
  private let playButton = UIButton(type: .system)
  private let changeProjectButton = UIButton(type: .system)
  private let scroll = UIScrollView()
  private var previewGenerator: AVAssetImageGenerator?
  private var previewGeneration = 0
  private var isPreparing = false
  private let uploads = ShareUploadCoordinator()
  private var appendLookup = ShareAppendDecision()
  private var temporaryURLs: [URL] = []
  private var didStart = false
  private var canDismiss = false
  private var didClose = false
  private var startingDraft = false
  private var draftID: String?
  private var serverURL: URL?
  private var token = ""
  private let appendRow = UIView()
  private let appendSwitch = UISwitch()
  private let appendCaption = UILabel()
  private var appendToLastUpload = UploadinyAppendPreferenceStore.read()
  private var lastUpload: LastUploadSummary?
  private var lastUploadGeneration = 0
  private var appendingTo: String?
  private var uploadedImageIDs: [String] = []

  override func viewDidLoad() {
    super.viewDidLoad()
    configureView()
  }

  override func viewDidAppear(_ animated: Bool) {
    super.viewDidAppear(animated)
    guard !didStart else { return }
    didStart = true
    loadProjects()
  }

  private func configureView() {
    preferredContentSize = CGSize(width: 0, height: 760)
    view.backgroundColor = SharePalette.canvas
    overrideUserInterfaceStyle = .light
    view.tintColor = SharePalette.accent
    titleLabel.text = "Choose a project"
    titleLabel.font = .preferredFont(forTextStyle: .title2)
    titleLabel.textColor = SharePalette.text
    titleLabel.numberOfLines = 2
    titleLabel.adjustsFontForContentSizeCategory = true
    detailLabel.text = "Loading your projects…"
    detailLabel.font = .preferredFont(forTextStyle: .subheadline)
    detailLabel.textColor = SharePalette.secondary
    detailLabel.numberOfLines = 0
    detailLabel.adjustsFontForContentSizeCategory = true
    uploadProgress.progressTintColor = SharePalette.accent
    uploadProgress.trackTintColor = SharePalette.line
    uploadProgress.isHidden = true
    uploadProgress.accessibilityLabel = "Upload progress"
    let brandIcon = UIImageView(image: UIImage(systemName: "square.and.arrow.up", withConfiguration: UIImage.SymbolConfiguration(pointSize: 18, weight: .semibold)))
    brandIcon.tintColor = SharePalette.accentText
    brandIcon.backgroundColor = SharePalette.accent.withAlphaComponent(0.16)
    brandIcon.contentMode = .center
    brandIcon.layer.cornerRadius = 10
    brandIcon.isAccessibilityElement = false
    brandIcon.widthAnchor.constraint(equalToConstant: 36).isActive = true
    brandIcon.heightAnchor.constraint(equalToConstant: 36).isActive = true
    var closeConfiguration = UIButton.Configuration.plain()
    closeConfiguration.image = UIImage(systemName: "xmark", withConfiguration: UIImage.SymbolConfiguration(pointSize: 16, weight: .semibold))
    closeConfiguration.baseForegroundColor = SharePalette.secondary
    closeButton.configuration = closeConfiguration
    closeButton.accessibilityLabel = "Close share sheet"
    closeButton.widthAnchor.constraint(greaterThanOrEqualToConstant: 44).isActive = true
    closeButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 44).isActive = true
    closeButton.setContentHuggingPriority(.required, for: .horizontal)
    closeButton.addTarget(self, action: #selector(closeExtension), for: .touchUpInside)
    let header = UIStackView(arrangedSubviews: [brandIcon, titleLabel, closeButton])
    header.axis = .horizontal
    header.alignment = .center
    header.spacing = 12
    header.translatesAutoresizingMaskIntoConstraints = false
    view.addSubview(header)
    emptyProjectIcon.image = UIImage(systemName: "folder.badge.plus", withConfiguration: UIImage.SymbolConfiguration(pointSize: 52, weight: .medium))
    emptyProjectIcon.tintColor = SharePalette.accentText
    emptyProjectIcon.contentMode = .scaleAspectFit
    emptyProjectIcon.isAccessibilityElement = false
    emptyProjectIcon.isHidden = true
    preview.contentMode = .scaleAspectFit
    preview.clipsToBounds = true
    preview.layer.cornerRadius = 16
    preview.backgroundColor = SharePalette.surface
    preview.isHidden = true
    configureReview()
    table.backgroundColor = .clear
    table.separatorStyle = .none
    table.indicatorStyle = .black
    table.alwaysBounceVertical = false
    table.register(UITableViewCell.self, forCellReuseIdentifier: "project")
    table.dataSource = self
    table.delegate = self
    table.rowHeight = UITableView.automaticDimension
    table.estimatedRowHeight = 88
    table.isHidden = true
    configureCredentialField(emailField, placeholder: "Email", secure: false)
    emailField.keyboardType = .emailAddress
    emailField.textContentType = .username
    emailField.autocapitalizationType = .none
    emailField.autocorrectionType = .no
    configureCredentialField(passwordField, placeholder: "Password", secure: true)
    passwordField.textContentType = .password
    emailField.isHidden = true
    passwordField.isHidden = true
    signInButton.isHidden = true
    let signInConfiguration = primaryButtonConfiguration(title: "Sign in securely", symbol: "lock.fill")
    signInButton.configuration = signInConfiguration
    signInButton.addTarget(self, action: #selector(beginSignIn), for: .touchUpInside)
    signOutButton.setTitle("Remove this iPhone sign-in", for: .normal)
    signOutButton.setTitleColor(SharePalette.secondary, for: .normal)
    signOutButton.titleLabel?.font = .preferredFont(forTextStyle: .footnote)
    signOutButton.titleLabel?.adjustsFontForContentSizeCategory = true
    let signOutHeight = signOutButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 44)
    signOutHeight.priority = .defaultHigh
    signOutHeight.isActive = true
    signOutButton.addTarget(self, action: #selector(signOut), for: .touchUpInside)
    signOutButton.isHidden = true
    uploadButton.configuration = primaryButtonConfiguration(title: "Upload assets", symbol: "arrow.up")
    uploadButton.isEnabled = false
    uploadButton.isHidden = true
    uploadButton.addTarget(self, action: #selector(beginUpload), for: .touchUpInside)
    footerSummary.font = .preferredFont(forTextStyle: .caption1)
    footerSummary.adjustsFontForContentSizeCategory = true
    footerSummary.textColor = SharePalette.secondary
    footerSummary.textAlignment = .center
    footerSummary.numberOfLines = 0
    footerSummary.isHidden = true
    let footer = UIStackView(arrangedSubviews: [footerSummary, uploadButton])
    footer.axis = .vertical
    footer.spacing = 10
    footer.translatesAutoresizingMaskIntoConstraints = false
    view.addSubview(footer)
    spinner.color = SharePalette.accentText
    spinner.startAnimating()
    let stack = UIStackView(arrangedSubviews: [emptyProjectIcon, detailLabel, uploadProgress, emailField, passwordField, signInButton, changeProjectButton, reviewPanel, preview, spinner, table, signOutButton])
    stack.axis = .vertical
    stack.alignment = .fill
    stack.spacing = 14
    stack.translatesAutoresizingMaskIntoConstraints = false
    scroll.translatesAutoresizingMaskIntoConstraints = false
    scroll.indicatorStyle = .black
    scroll.keyboardDismissMode = .interactive
    view.addSubview(scroll)
    scroll.addSubview(stack)
    let emptyIconHeight = emptyProjectIcon.heightAnchor.constraint(equalToConstant: 64)
    emptyIconHeight.priority = .defaultHigh
    tableHeight = table.heightAnchor.constraint(equalToConstant: 320)
    tableHeight.priority = .defaultHigh
    previewHeight = preview.heightAnchor.constraint(equalToConstant: 320)
    previewHeight.priority = .defaultHigh
    let uploadHeight = uploadButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 56)
    uploadHeight.priority = .defaultHigh
    let emailHeight = emailField.heightAnchor.constraint(greaterThanOrEqualToConstant: 52)
    emailHeight.priority = .defaultHigh
    let passwordHeight = passwordField.heightAnchor.constraint(greaterThanOrEqualToConstant: 52)
    passwordHeight.priority = .defaultHigh
    let signInHeight = signInButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 56)
    signInHeight.priority = .defaultHigh
    NSLayoutConstraint.activate([
      header.leadingAnchor.constraint(equalTo: view.leadingAnchor, constant: 20),
      header.trailingAnchor.constraint(equalTo: view.trailingAnchor, constant: -20),
      header.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor, constant: 12),
      scroll.leadingAnchor.constraint(equalTo: view.leadingAnchor),
      scroll.trailingAnchor.constraint(equalTo: view.trailingAnchor),
      scroll.topAnchor.constraint(equalTo: header.bottomAnchor, constant: 16),
      scroll.bottomAnchor.constraint(equalTo: footer.topAnchor, constant: -16),
      footer.leadingAnchor.constraint(equalTo: view.leadingAnchor, constant: 20),
      footer.trailingAnchor.constraint(equalTo: view.trailingAnchor, constant: -20),
      footer.bottomAnchor.constraint(equalTo: view.keyboardLayoutGuide.topAnchor, constant: -12),
      stack.leadingAnchor.constraint(equalTo: scroll.contentLayoutGuide.leadingAnchor, constant: 20),
      stack.trailingAnchor.constraint(equalTo: scroll.contentLayoutGuide.trailingAnchor, constant: -20),
      stack.topAnchor.constraint(equalTo: scroll.contentLayoutGuide.topAnchor, constant: 8),
      stack.bottomAnchor.constraint(equalTo: scroll.contentLayoutGuide.bottomAnchor, constant: -16),
      stack.widthAnchor.constraint(equalTo: scroll.frameLayoutGuide.widthAnchor, constant: -40),
      tableHeight, emptyIconHeight, previewHeight, uploadHeight, emailHeight, passwordHeight, signInHeight,
    ])
    let gesture = UITapGestureRecognizer(target: self, action: #selector(dismissAfterSuccess))
    gesture.cancelsTouchesInView = false
    view.addGestureRecognizer(gesture)
  }

  private func primaryButtonConfiguration(title: String, symbol: String) -> UIButton.Configuration {
    var configuration = UIButton.Configuration.filled()
    configuration.title = title
    configuration.baseBackgroundColor = SharePalette.accent
    configuration.baseForegroundColor = .white
    configuration.background.cornerRadius = 16
    configuration.image = UIImage(systemName: symbol, withConfiguration: UIImage.SymbolConfiguration(weight: .semibold))
    configuration.imagePadding = 10
    configuration.titleTextAttributesTransformer = UIConfigurationTextAttributesTransformer { attributes in
      var attributes = attributes
      attributes.font = .preferredFont(forTextStyle: .headline)
      return attributes
    }
    configuration.contentInsets = NSDirectionalEdgeInsets(top: 16, leading: 20, bottom: 16, trailing: 20)
    return configuration
  }

  private func configureReview() {
    reviewPanel.axis = .vertical
    reviewPanel.spacing = 8
    reviewPanel.isHidden = true
    configureAppendRow()
    reviewPanel.addArrangedSubview(appendRow)
    reviewPanel.setCustomSpacing(14, after: appendRow)
    mediaLabel.font = .preferredFont(forTextStyle: .headline)
    mediaLabel.adjustsFontForContentSizeCategory = true
    mediaLabel.textColor = SharePalette.text
    assetLabel.font = .preferredFont(forTextStyle: .subheadline)
    assetLabel.adjustsFontForContentSizeCategory = true
    assetLabel.textColor = SharePalette.secondary
    assetLabel.textAlignment = .center
    assetLabel.setContentHuggingPriority(.required, for: .horizontal)
    configureNavigationButton(previousButton, title: "Previous asset", symbol: "chevron.left", action: #selector(previousAsset))
    configureNavigationButton(nextButton, title: "Next asset", symbol: "chevron.right", action: #selector(nextAsset))
    let navigation = UIStackView(arrangedSubviews: [mediaLabel, previousButton, assetLabel, nextButton])
    navigation.alignment = .center
    navigation.spacing = 4
    reviewPanel.addArrangedSubview(navigation)
    assetPreview.contentMode = .scaleAspectFit
    assetPreview.backgroundColor = SharePalette.surface
    assetPreview.layer.cornerRadius = 16
    assetPreview.clipsToBounds = true
    assetPreview.isAccessibilityElement = true
    reviewPanel.addArrangedSubview(assetPreview)
    assetPreviewHeight = assetPreview.heightAnchor.constraint(equalToConstant: 160)
    assetPreviewHeight.priority = .defaultHigh
    assetPreviewHeight.isActive = true
    assetNameLabel.font = .preferredFont(forTextStyle: .caption1)
    assetNameLabel.adjustsFontForContentSizeCategory = true
    assetNameLabel.textColor = SharePalette.secondary
    assetNameLabel.numberOfLines = 1
    assetNameLabel.lineBreakMode = .byTruncatingMiddle
    reviewPanel.addArrangedSubview(assetNameLabel)
    var playConfiguration = UIButton.Configuration.tinted()
    playConfiguration.title = "Play recording"
    playConfiguration.image = UIImage(systemName: "play.fill")
    playConfiguration.imagePadding = 8
    playConfiguration.baseForegroundColor = SharePalette.accentText
    playButton.configuration = playConfiguration
    let playHeight = playButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 44)
    playHeight.priority = .defaultHigh
    playHeight.isActive = true
    playButton.addTarget(self, action: #selector(playRecording), for: .touchUpInside)
    reviewPanel.addArrangedSubview(playButton)
    assetStrip.backgroundColor = .clear
    assetStrip.showsHorizontalScrollIndicator = false
    assetStrip.dataSource = self
    assetStrip.delegate = self
    assetStrip.register(ShareAssetCell.self, forCellWithReuseIdentifier: "asset")
    let stripHeight = assetStrip.heightAnchor.constraint(equalToConstant: 76)
    stripHeight.priority = .defaultHigh
    stripHeight.isActive = true
    reviewPanel.addArrangedSubview(assetStrip)
    reviewPanel.setCustomSpacing(16, after: assetStrip)
    feedbackCard.backgroundColor = SharePalette.surface
    feedbackCard.layer.cornerRadius = 18
    feedbackCard.layer.borderWidth = 1
    feedbackCard.layer.borderColor = SharePalette.line.cgColor
    let pencil = UIImageView(image: UIImage(systemName: "text.bubble", withConfiguration: UIImage.SymbolConfiguration(pointSize: 18, weight: .semibold)))
    pencil.tintColor = SharePalette.accentText
    pencil.isAccessibilityElement = false
    pencil.widthAnchor.constraint(equalToConstant: 24).isActive = true
    let heading = UILabel()
    heading.text = "Your feedback"
    heading.font = .preferredFont(forTextStyle: .headline)
    heading.adjustsFontForContentSizeCategory = true
    heading.textColor = SharePalette.text
    heading.numberOfLines = 0
    let optional = UILabel()
    optional.text = "Optional"
    optional.font = .preferredFont(forTextStyle: .caption1)
    optional.adjustsFontForContentSizeCategory = true
    optional.textColor = SharePalette.secondary
    optional.setContentHuggingPriority(.required, for: .horizontal)
    let feedbackHeading = UIStackView(arrangedSubviews: [pencil, heading, optional])
    feedbackHeading.axis = .horizontal
    feedbackHeading.alignment = .center
    feedbackHeading.spacing = 8
    feedbackField.delegate = self
    feedbackField.font = .preferredFont(forTextStyle: .body)
    feedbackField.adjustsFontForContentSizeCategory = true
    feedbackField.textColor = SharePalette.text
    feedbackField.tintColor = SharePalette.accentText
    feedbackField.backgroundColor = .clear
    feedbackField.textContainerInset = UIEdgeInsets(top: 6, left: 0, bottom: 6, right: 0)
    feedbackField.textContainer.lineFragmentPadding = 0
    feedbackField.accessibilityLabel = "Feedback for this asset"
    feedbackField.accessibilityHint = "Included with this asset when you upload."
    feedbackHeight = feedbackField.heightAnchor.constraint(equalToConstant: 80)
    feedbackHeight.priority = .defaultHigh
    feedbackHeight.isActive = true
    feedbackPlaceholder.text = "What needs to change?\nDescribe the improvement you have in mind…"
    feedbackPlaceholder.font = .preferredFont(forTextStyle: .body)
    feedbackPlaceholder.adjustsFontForContentSizeCategory = true
    feedbackPlaceholder.textColor = SharePalette.secondary
    feedbackPlaceholder.numberOfLines = 0
    feedbackPlaceholder.isUserInteractionEnabled = false
    feedbackPlaceholder.isAccessibilityElement = false
    feedbackPlaceholder.translatesAutoresizingMaskIntoConstraints = false
    feedbackField.addSubview(feedbackPlaceholder)
    NSLayoutConstraint.activate([
      feedbackPlaceholder.topAnchor.constraint(equalTo: feedbackField.frameLayoutGuide.topAnchor, constant: 6),
      feedbackPlaceholder.leadingAnchor.constraint(equalTo: feedbackField.frameLayoutGuide.leadingAnchor),
      feedbackPlaceholder.trailingAnchor.constraint(equalTo: feedbackField.frameLayoutGuide.trailingAnchor),
    ])
    let toolbar = UIToolbar()
    toolbar.sizeToFit()
    toolbar.items = [UIBarButtonItem(systemItem: .flexibleSpace), UIBarButtonItem(title: "Done", style: .done, target: self, action: #selector(finishWriting))]
    feedbackField.inputAccessoryView = toolbar
    feedbackStatus.font = .preferredFont(forTextStyle: .caption1)
    feedbackStatus.adjustsFontForContentSizeCategory = true
    feedbackStatus.textColor = SharePalette.secondary
    feedbackStatus.numberOfLines = 0
    let feedbackContent = UIStackView(arrangedSubviews: [feedbackHeading, feedbackField, feedbackStatus])
    feedbackContent.axis = .vertical
    feedbackContent.spacing = 8
    feedbackContent.translatesAutoresizingMaskIntoConstraints = false
    feedbackCard.addSubview(feedbackContent)
    NSLayoutConstraint.activate([
      feedbackContent.leadingAnchor.constraint(equalTo: feedbackCard.leadingAnchor, constant: 14),
      feedbackContent.trailingAnchor.constraint(equalTo: feedbackCard.trailingAnchor, constant: -14),
      feedbackContent.topAnchor.constraint(equalTo: feedbackCard.topAnchor, constant: 14),
      feedbackContent.bottomAnchor.constraint(equalTo: feedbackCard.bottomAnchor, constant: -14),
    ])
    reviewPanel.addArrangedSubview(feedbackCard)
    var projectConfiguration = UIButton.Configuration.gray()
    projectConfiguration.baseForegroundColor = SharePalette.text
    projectConfiguration.baseBackgroundColor = SharePalette.surface
    projectConfiguration.image = UIImage(systemName: "chevron.up.chevron.down", withConfiguration: UIImage.SymbolConfiguration(pointSize: 12, weight: .semibold))
    projectConfiguration.imagePlacement = .trailing
    projectConfiguration.imagePadding = 12
    projectConfiguration.titleAlignment = .leading
    projectConfiguration.titleLineBreakMode = .byTruncatingTail
    projectConfiguration.contentInsets = NSDirectionalEdgeInsets(top: 10, leading: 14, bottom: 10, trailing: 14)
    projectConfiguration.background.cornerRadius = 12
    changeProjectButton.configuration = projectConfiguration
    changeProjectButton.contentHorizontalAlignment = .fill
    let projectHeight = changeProjectButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 48)
    projectHeight.priority = .defaultHigh
    projectHeight.isActive = true
    changeProjectButton.isHidden = true
    changeProjectButton.addTarget(self, action: #selector(changeProject), for: .touchUpInside)
  }

  private func configureAppendRow() {
    appendRow.backgroundColor = SharePalette.surface
    appendRow.layer.cornerRadius = 12
    appendRow.layer.borderWidth = 1
    appendRow.layer.borderColor = SharePalette.line.cgColor
    appendRow.isHidden = true
    let title = UILabel()
    title.text = "Add to the last upload"
    title.font = .preferredFont(forTextStyle: .body)
    title.adjustsFontForContentSizeCategory = true
    title.textColor = SharePalette.text
    title.numberOfLines = 0
    title.isAccessibilityElement = false
    appendCaption.font = .preferredFont(forTextStyle: .caption1)
    appendCaption.adjustsFontForContentSizeCategory = true
    appendCaption.textColor = SharePalette.secondary
    appendCaption.numberOfLines = 0
    appendCaption.isAccessibilityElement = false
    let labels = UIStackView(arrangedSubviews: [title, appendCaption])
    labels.axis = .vertical
    labels.spacing = 2
    appendSwitch.onTintColor = SharePalette.accent
    appendSwitch.isOn = appendToLastUpload
    appendSwitch.accessibilityLabel = "Add to the last upload"
    appendSwitch.setContentHuggingPriority(.required, for: .horizontal)
    appendSwitch.setContentCompressionResistancePriority(.required, for: .horizontal)
    appendSwitch.addTarget(self, action: #selector(appendPreferenceChanged), for: .valueChanged)
    let content = UIStackView(arrangedSubviews: [labels, appendSwitch])
    content.axis = .horizontal
    content.alignment = .center
    content.spacing = 12
    content.translatesAutoresizingMaskIntoConstraints = false
    appendRow.addSubview(content)
    let rowHeight = appendRow.heightAnchor.constraint(greaterThanOrEqualToConstant: 52)
    rowHeight.priority = .defaultHigh
    NSLayoutConstraint.activate([
      content.leadingAnchor.constraint(equalTo: appendRow.leadingAnchor, constant: 14),
      content.trailingAnchor.constraint(equalTo: appendRow.trailingAnchor, constant: -14),
      content.topAnchor.constraint(equalTo: appendRow.topAnchor, constant: 10),
      content.bottomAnchor.constraint(equalTo: appendRow.bottomAnchor, constant: -10),
      rowHeight,
    ])
    // The whole row is the touch target; taps on the switch itself stay with the switch.
    appendRow.addGestureRecognizer(UITapGestureRecognizer(target: self, action: #selector(toggleAppendRow)))
  }

  @objc private func toggleAppendRow() {
    appendSwitch.setOn(!appendSwitch.isOn, animated: true)
    appendPreferenceChanged()
  }

  @objc private func appendPreferenceChanged() {
    appendToLastUpload = appendSwitch.isOn
    UploadinyAppendPreferenceStore.write(appendSwitch.isOn)
    updateUploadAvailability()
  }

  private func updateUploadAvailability() {
    guard !review.files.isEmpty, !isPreparing, !reviewPanel.isHidden else { return }
    let retry = appendToLastUpload && appendLookup.failed
    uploadButton.isEnabled = !appendToLastUpload || !appendLookup.loading
    uploadButton.alpha = uploadButton.isEnabled ? 1 : 0.5
    uploadButton.configuration?.title = retry ? "Retry last upload lookup" : "Upload \(review.files.count) asset\(review.files.count == 1 ? "" : "s")"
    uploadButton.accessibilityLabel = uploadButton.configuration?.title
  }

  private func updateAppendRow() {
    if appendLookup.loading || appendLookup.failed {
      appendRow.isHidden = false
      appendCaption.text = appendLookup.loading ? "Checking the last upload…" : "Lookup failed. Retry before adding to the last upload."
      appendSwitch.accessibilityHint = appendCaption.text
      updateUploadAvailability()
      return
    }
    guard let lastUpload, lastUpload.projectID == selectedProject?.id else {
      appendRow.isHidden = true
      appendSwitch.accessibilityHint = nil
      return
    }
    let caption = "Last upload: \(lastUploadDate(lastUpload.completedAt)) · \(lastUpload.fileCount) file\(lastUpload.fileCount == 1 ? "" : "s")"
    appendCaption.text = caption
    appendSwitch.accessibilityHint = caption
    appendSwitch.isOn = appendToLastUpload
    appendRow.isHidden = false
  }

  private func lastUploadDate(_ date: Date) -> String {
    let calendar = Calendar.current
    let time = DateFormatter()
    time.setLocalizedDateFormatFromTemplate("jjmm")
    let clock = time.string(from: date)
    if calendar.isDateInToday(date) { return "Today \(clock)" }
    if calendar.isDateInYesterday(date) { return "Yesterday \(clock)" }
    let day = DateFormatter()
    day.setLocalizedDateFormatFromTemplate(calendar.isDate(date, equalTo: Date(), toGranularity: .year) ? "dMMM" : "dMMMy")
    return "\(day.string(from: date)) \(clock)"
  }

  private func fetchLastUpload(for project: Project) {
    lastUploadGeneration += 1
    let generation = lastUploadGeneration
    appendLookup.begin(projectID: project.id, generation: generation)
    lastUpload = nil
    updateAppendRow()
    guard let base = serverURL, !token.isEmpty else { return }
    var request = authorizedRequest(base.appendingPathComponent("projects").appendingPathComponent(project.slug).appendingPathComponent("last-chunk"))
    request.timeoutInterval = 30
    let session = secureSession(requestTimeout: 30, resourceTimeout: 30)
    uploads.dataTask(session: session, with: request) { [weak self] data, response, error in
      DispatchQueue.main.async {
        guard let self, !self.didClose, self.lastUploadGeneration == generation, self.selectedProject?.id == project.id else { return }
        if let response = response as? HTTPURLResponse, response.statusCode == 401 {
          self.requireSignIn("Your iPhone sign-in is no longer valid. Sign in again.")
          return
        }
        guard error == nil, let response = response as? HTTPURLResponse, response.statusCode == 200,
              let data, let result = try? JSONDecoder().decode(LastUploadResult.self, from: data) else {
          self.appendLookup.resolve(projectID: project.id, generation: generation, success: false)
          self.updateAppendRow()
          return
        }
        if let chunk = result.chunk {
          guard let completedAt = ISO8601DateFormatter().date(from: chunk.completed_at) else {
            self.appendLookup.resolve(projectID: project.id, generation: generation, success: false)
            self.updateAppendRow()
            return
          }
          self.lastUpload = LastUploadSummary(projectID: project.id, id: chunk.id, completedAt: completedAt, fileCount: chunk.file_count)
        }
        self.appendLookup.resolve(projectID: project.id, generation: generation, success: true)
        self.updateAppendRow()
        self.updateUploadAvailability()
      }
    }.resume()
  }

  private func configureNavigationButton(_ button: UIButton, title: String, symbol: String, action: Selector) {
    var configuration = UIButton.Configuration.plain()
    configuration.image = UIImage(systemName: symbol, withConfiguration: UIImage.SymbolConfiguration(pointSize: 13, weight: .semibold))
    configuration.baseForegroundColor = SharePalette.text
    button.configuration = configuration
    button.accessibilityLabel = title
    let width = button.widthAnchor.constraint(equalToConstant: 44)
    width.priority = .defaultHigh
    width.isActive = true
    let height = button.heightAnchor.constraint(equalToConstant: 44)
    height.priority = .defaultHigh
    height.isActive = true
    button.addTarget(self, action: action, for: .touchUpInside)
  }

  func collectionView(_ collectionView: UICollectionView, numberOfItemsInSection section: Int) -> Int { review.files.count }

  func collectionView(_ collectionView: UICollectionView, cellForItemAt indexPath: IndexPath) -> UICollectionViewCell {
    let cell = collectionView.dequeueReusableCell(withReuseIdentifier: "asset", for: indexPath) as! ShareAssetCell
    let file = review.files[indexPath.item]
    cell.representedIndex = indexPath.item
    cell.configure(number: indexPath.item + 1, file: file, selected: review.selectedIndex == indexPath.item)
    if !file.mime.hasPrefix("video/") {
      DispatchQueue.global(qos: .userInitiated).async { [weak self, weak cell] in
        guard let self else { return }
        let thumbnail = self.imagePreview(file.url, maxPixelSize: 160)
        DispatchQueue.main.async {
          guard !self.didClose, let cell, cell.representedIndex == indexPath.item else { return }
          cell.imageView.image = thumbnail ?? UIImage(systemName: "photo")
        }
      }
    }
    return cell
  }

  func collectionView(_ collectionView: UICollectionView, didSelectItemAt indexPath: IndexPath) {
    selectAsset(indexPath.item)
  }

  private func updateFeedbackPresentation() {
    let hasNote = !(feedbackField.text ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    feedbackStatus.text = hasNote ? "Included with this asset on upload" : "Add context, a problem, or the change you want."
    feedbackStatus.textColor = hasNote ? SharePalette.mint : SharePalette.secondary
    feedbackPlaceholder.isHidden = !(feedbackField.text ?? "").isEmpty
    let notes = review.files.filter { !$0.comments.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }.count
    footerSummary.text = "\(review.files.count) asset\(review.files.count == 1 ? "" : "s") · \(notes) with feedback"
    for indexPath in assetStrip.indexPathsForVisibleItems {
      guard let cell = assetStrip.cellForItem(at: indexPath) as? ShareAssetCell else { continue }
      cell.updateNote(!review.files[indexPath.item].comments.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
    }
    let width = max(feedbackField.bounds.width, view.bounds.width - 72)
    let textHeight = feedbackField.sizeThatFits(CGSize(width: width, height: .greatestFiniteMagnitude)).height
    feedbackHeight.constant = min(160, max(80, textHeight))
  }

  private func showReview() {
    guard !didClose, !review.files.isEmpty, let project = selectedProject else { return }
    spinner.stopAnimating()
    spinner.isHidden = true
    table.isHidden = true
    signOutButton.isHidden = true
    preview.isHidden = true
    emailField.isHidden = true
    passwordField.isHidden = true
    signInButton.isHidden = true
    titleLabel.text = "Share feedback"
    detailLabel.isHidden = true
    changeProjectButton.configuration?.title = project.name
    changeProjectButton.configuration?.subtitle = (projectIsRemembered ? "Last project used" : "Upload to project") + " · Tap to change"
    changeProjectButton.accessibilityLabel = "Project: \(project.name). Change project"
    changeProjectButton.isHidden = false
    updateAppendRow()
    reviewPanel.isHidden = false
    uploadButton.configuration?.title = "Upload \(review.files.count) asset\(review.files.count == 1 ? "" : "s")"
    footerSummary.isHidden = false
    uploadButton.accessibilityLabel = "Upload \(review.files.count) asset\(review.files.count == 1 ? "" : "s")"
    uploadButton.isEnabled = true
    uploadButton.alpha = 1
    uploadButton.isHidden = false
    updateUploadAvailability()
    preferredContentSize = CGSize(width: 0, height: 760)
    showAsset()
    scroll.setContentOffset(.zero, animated: false)
  }

  private func showAsset() {
    guard review.files.indices.contains(review.selectedIndex) else { return }
    let file = review.files[review.selectedIndex]
    assetLabel.text = "\(review.selectedIndex + 1) / \(review.files.count)"
    mediaLabel.text = file.mime.hasPrefix("video/") ? "Recording" : "Screenshot"
    previousButton.isHidden = review.files.count == 1
    nextButton.isHidden = review.files.count == 1
    assetStrip.isHidden = review.files.count == 1
    assetPreviewHeight.constant = review.files.count == 1 ? 210 : 160
    assetNameLabel.text = file.name
    previousButton.isEnabled = review.selectedIndex > 0
    nextButton.isEnabled = review.selectedIndex + 1 < review.files.count
    previousButton.alpha = previousButton.isEnabled ? 1 : 0.35
    nextButton.alpha = nextButton.isEnabled ? 1 : 0.35
    feedbackField.text = file.comments
    feedbackField.accessibilityLabel = "Feedback for asset \(review.selectedIndex + 1), \(file.name)"
    feedbackPlaceholder.isHidden = !file.comments.isEmpty
    playButton.isHidden = !file.mime.hasPrefix("video/")
    assetStrip.reloadData()
    assetStrip.layoutIfNeeded()
    if assetStrip.numberOfItems(inSection: 0) > review.selectedIndex {
      assetStrip.scrollToItem(at: IndexPath(item: review.selectedIndex, section: 0), at: .centeredHorizontally, animated: !UIAccessibility.isReduceMotionEnabled)
    }
    updateFeedbackPresentation()
    loadAssetPreview(file)
  }

  private func loadAssetPreview(_ file: SharedFile) {
    previewGeneration += 1
    let generation = previewGeneration
    previewGenerator?.cancelAllCGImageGeneration()
    previewGenerator = nil
    assetPreview.image = nil
    assetPreview.accessibilityLabel = "Loading preview for \(file.name)"
    if file.mime.hasPrefix("video/") {
      let generator = AVAssetImageGenerator(asset: AVURLAsset(url: file.url))
      generator.appliesPreferredTrackTransform = true
      generator.maximumSize = CGSize(width: 1280, height: 1280)
      previewGenerator = generator
      generator.generateCGImageAsynchronously(for: .zero) { [weak self] image, _, _ in
        DispatchQueue.main.async {
          guard let self, !self.didClose, self.previewGeneration == generation else { return }
          self.displayAssetPreview(image.map { UIImage(cgImage: $0) }, file: file)
        }
      }
    } else {
      DispatchQueue.global(qos: .userInitiated).async { [weak self] in
        guard let self else { return }
        let image = self.imagePreview(file.url)
        DispatchQueue.main.async {
          guard !self.didClose, self.previewGeneration == generation else { return }
          self.displayAssetPreview(image, file: file)
        }
      }
    }
  }

  private func displayAssetPreview(_ image: UIImage?, file: SharedFile) {
    assetPreview.image = image ?? UIImage(systemName: file.mime.hasPrefix("video/") ? "video" : "photo")
    assetPreview.tintColor = SharePalette.secondary
    assetPreview.accessibilityLabel = image == nil ? "Preview unavailable for \(file.name)" : "Preview of \(file.name)"
    assetNameLabel.text = file.name + (image == nil ? " · Preview unavailable" : "")
  }

  private func saveCurrentFeedback() {
    review.updateComments(feedbackField.text ?? "")
  }

  func textViewDidChange(_ textView: UITextView) {
    saveCurrentFeedback()
    updateFeedbackPresentation()
  }

  func textViewDidEndEditing(_ textView: UITextView) {
    feedbackCard.layer.borderColor = SharePalette.line.cgColor
  }

  func textViewDidBeginEditing(_ textView: UITextView) {
    feedbackCard.layer.borderColor = SharePalette.accent.cgColor
    scroll.scrollRectToVisible(textView.convert(textView.bounds, to: scroll), animated: true)
  }

  override func viewDidLayoutSubviews() {
    super.viewDidLayoutSubviews()
    if feedbackField.isFirstResponder {
      scroll.scrollRectToVisible(feedbackField.convert(feedbackField.bounds, to: scroll), animated: false)
    }
  }

  @objc private func previousAsset() { selectAsset(review.selectedIndex - 1) }
  @objc private func nextAsset() { selectAsset(review.selectedIndex + 1) }

  private func selectAsset(_ index: Int) {
    saveCurrentFeedback()
    review.select(index)
    showAsset()
    UISelectionFeedbackGenerator().selectionChanged()
  }

  @objc private func finishWriting() { view.endEditing(true) }

  @objc private func changeProject() {
    saveCurrentFeedback()
    view.endEditing(true)
    reviewPanel.isHidden = true
    changeProjectButton.isHidden = true
    uploadButton.isHidden = true
    footerSummary.isHidden = true
    titleLabel.text = "Choose a project"
    detailLabel.isHidden = false
    detailLabel.text = "Your asset feedback stays with your files."
    table.isHidden = false
    table.reloadData()
    signOutButton.isHidden = false
    scroll.setContentOffset(.zero, animated: false)
  }

  @objc private func playRecording() {
    guard review.files.indices.contains(review.selectedIndex) else { return }
    view.endEditing(true)
    let controller = AVPlayerViewController()
    controller.player = AVPlayer(url: review.files[review.selectedIndex].url)
    present(controller, animated: true) { controller.player?.play() }
  }

  private func configureCredentialField(_ field: UITextField, placeholder: String, secure: Bool) {
    field.placeholder = placeholder
    field.textColor = SharePalette.text
    field.tintColor = SharePalette.accent
    field.backgroundColor = SharePalette.surface
    field.layer.cornerRadius = 14
    field.layer.borderWidth = 1
    field.layer.borderColor = SharePalette.line.cgColor
    field.font = .preferredFont(forTextStyle: .body)
    field.isSecureTextEntry = secure
    field.clearButtonMode = .whileEditing
    field.leftView = UIView(frame: CGRect(x: 0, y: 0, width: 16, height: 1))
    field.leftViewMode = .always
    field.rightView = UIView(frame: CGRect(x: 0, y: 0, width: 16, height: 1))
    field.rightViewMode = .always
  }

  private func loadProjects() {
    guard configureServer(), !providers.isEmpty else { return }
    guard let storedToken = UploadinyDeviceTokenStore.read() else {
      showSignIn()
      return
    }
    token = storedToken
    fetchProjects()
  }

  private func configureServer() -> Bool {
    guard let urlString = Bundle.main.object(forInfoDictionaryKey: "UploadinyServerURL") as? String,
          let base = URL(string: urlString),
          base.scheme == "https",
          base.host == "uploadiny.com",
          base.port == nil,
          base.user == nil,
          base.password == nil,
          base.path == "/api",
          base.query == nil,
          base.fragment == nil else {
      showError("This Uploadiny build is not configured for its secure service.")
      return false
    }
    serverURL = base
    providers = (extensionContext?.inputItems as? [NSExtensionItem] ?? []).flatMap { $0.attachments ?? [] }
    if providers.isEmpty {
      showError("No images or recordings were included in this share.")
      return false
    }
    return true
  }

  private func fetchProjects() {
    guard let base = serverURL else { return }
    var request = authorizedRequest(base.appendingPathComponent("projects"))
    request.timeoutInterval = 30
    let session = secureSession(requestTimeout: 30, resourceTimeout: 30)
    uploads.dataTask(session: session, with: request) { [weak self] data, response, error in
      guard let self else { return }
      if let response = response as? HTTPURLResponse, response.statusCode == 401 {
        self.requireSignIn("Your iPhone sign-in is no longer valid. Sign in again.")
        return
      }
      guard error == nil, let response = response as? HTTPURLResponse, response.statusCode == 200,
            let data, let result = try? JSONDecoder().decode(ProjectList.self, from: data) else {
        self.showError("Your projects could not be loaded. Check your connection and sign in again.")
        return
      }
      DispatchQueue.main.async {
        self.projects = result.projects
        self.spinner.stopAnimating()
      self.uploadProgress.isHidden = true
        self.spinner.isHidden = true
        self.emailField.isHidden = true
        self.passwordField.isHidden = true
        self.signInButton.isHidden = true
        self.signOutButton.isHidden = false
        if self.projects.isEmpty {
          self.emptyProjectIcon.isHidden = false
          self.titleLabel.text = "No projects found"
          self.titleLabel.textAlignment = .center
          self.detailLabel.textAlignment = .center
          self.detailLabel.text = "Please create a project on uploadiny.com,\nthen share your files again."
          self.table.isHidden = true
          self.uploadButton.isHidden = true
          self.footerSummary.isHidden = true
          self.preferredContentSize = CGSize(width: 0, height: 360)
          return
        }
        self.detailLabel.isHidden = false
        self.titleLabel.text = "Choose a project"
        self.titleLabel.textAlignment = .left
        self.detailLabel.textAlignment = .left
        self.detailLabel.text = "\(self.providers.count) file\(self.providers.count == 1 ? "" : "s") will stay together in one feedback group."
        self.tableHeight.constant = min(320, CGFloat(self.projects.count) * 88)
        self.table.isHidden = false
        self.table.reloadData()
        self.uploadButton.isHidden = true
        self.footerSummary.isHidden = true
        self.reviewPanel.isHidden = true
        self.changeProjectButton.isHidden = true
        // Keep this session's choice if it is still listed, else use the project of the last successful upload.
        if !self.didClose, let slug = self.selectedProject?.slug ?? UploadinyLastProjectStore.read(),
           let project = self.projects.first(where: { $0.slug == slug }) {
          self.selectProject(project, remembered: self.selectedProject == nil)
        }
      }
    }.resume()
  }

  private func showSignIn(_ message: String = "Sign in once to give this iPhone limited upload access. Your password is never stored.") {
    DispatchQueue.main.async {
      self.spinner.stopAnimating()
      self.uploadProgress.isHidden = true
      self.spinner.isHidden = true
      self.emptyProjectIcon.isHidden = true
      self.preview.isHidden = true
      self.reviewPanel.isHidden = true
      self.changeProjectButton.isHidden = true
      self.table.isHidden = true
      self.uploadButton.isHidden = true
      self.footerSummary.isHidden = true
      self.signOutButton.isHidden = true
      self.emailField.isHidden = false
      self.passwordField.isHidden = false
      self.signInButton.isHidden = false
      self.signInButton.isEnabled = true
      self.detailLabel.isHidden = false
      self.titleLabel.text = "Sign in to Uploadiny"
      self.titleLabel.textAlignment = .left
      self.detailLabel.text = message
      self.detailLabel.textAlignment = .left
      self.preferredContentSize = CGSize(width: 0, height: 420)
    }
  }

  @objc private func beginSignIn() {
    guard let base = serverURL else { return }
    let email = emailField.text?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
    let password = passwordField.text ?? ""
    guard !email.isEmpty, !password.isEmpty else {
      showSignIn("Enter your Uploadiny email and password to continue.")
      return
    }
    guard let body = try? JSONSerialization.data(withJSONObject: ["email": email, "password": password]) else {
      showSignIn("This sign-in request could not be prepared.")
      return
    }
    passwordField.text = nil
    signInButton.isEnabled = false
    titleLabel.text = "Signing in…"
    detailLabel.text = "Creating limited access for this iPhone."
    var request = URLRequest(url: base.appendingPathComponent("device-tokens"))
    request.httpMethod = "POST"
    request.setValue("application/json", forHTTPHeaderField: "Accept")
    request.setValue("application/json", forHTTPHeaderField: "Content-Type")
    request.cachePolicy = .reloadIgnoringLocalCacheData
    request.timeoutInterval = 30
    request.httpBody = body
    let session = secureSession(requestTimeout: 30, resourceTimeout: 30)
    uploads.dataTask(session: session, with: request) { [weak self] data, response, error in
      guard let self else { return }
      guard error == nil, let response = response as? HTTPURLResponse, response.statusCode == 201,
            let data, let result = try? JSONDecoder().decode(DeviceTokenResult.self, from: data) else {
        self.showSignIn("Sign-in could not be completed. Check your details and try again.")
        return
      }
      do {
        try UploadinyDeviceTokenStore.write(result.token)
      } catch {
        self.showSignIn("This iPhone could not store its limited access securely. Try again.")
        return
      }
      DispatchQueue.main.async {
        self.token = result.token
        self.emailField.text = nil
        self.loadProjects()
      }
    }.resume()
  }

  @objc private func signOut() {
    UploadinyDeviceTokenStore.delete()
    token = ""
    projects.removeAll()
    selectedProject = nil
    showSignIn("This iPhone sign-in was removed. Revoke iPhone access in your Uploadiny workspace to invalidate access everywhere.")
  }

  private func requireSignIn(_ message: String) {
    UploadinyDeviceTokenStore.delete()
    token = ""
    draftID = nil
    // Keep prepared assets and their notes available after signing in again.
    showSignIn(message)
  }

  private func secureSession(requestTimeout: TimeInterval = 600, resourceTimeout: TimeInterval = 600) -> URLSession {
    return uploads.session(delegate: self, requestTimeout: requestTimeout, resourceTimeout: resourceTimeout)
  }

  func urlSession(_ session: URLSession, task: URLSessionTask, didSendBodyData bytesSent: Int64, totalBytesSent: Int64, totalBytesExpectedToSend: Int64) {
    guard totalBytesExpectedToSend > 0 else { return }
    let fraction = min(1, Double(totalBytesSent) / Double(totalBytesExpectedToSend))
    DispatchQueue.main.async { [weak self] in
      guard let self, !self.didClose, task === self.currentUploadTask else { return }
      self.showUploadProgress(index: self.currentFileIndex, fileFraction: fraction)
    }
  }

  private func showUploadProgress(index: Int, fileFraction: Double) {
    let sent = Double(uploadDoneBytes) + Double(currentFileBytes) * fileFraction
    let overall = Float(min(1, sent / Double(uploadTotalBytes)))
    uploadProgress.setProgress(overall, animated: true)
    let percent = Int((overall * 100).rounded())
    detailLabel.text = "Sending \(index + 1) of \(review.files.count) · \(percent)%"
    uploadProgress.accessibilityValue = "\(percent) percent"
  }

  private static func fileBytes(_ url: URL) -> Int64 {
    Int64((try? url.resourceValues(forKeys: [.fileSizeKey]))?.fileSize ?? 0)
  }

  func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest, completionHandler: @escaping (URLRequest?) -> Void) {
    completionHandler(nil)
  }

  func tableView(_ tableView: UITableView, numberOfRowsInSection section: Int) -> Int { projects.count }

  func tableView(_ tableView: UITableView, cellForRowAt indexPath: IndexPath) -> UITableViewCell {
    let project = projects[indexPath.row]
    let selected = selectedProject?.id == project.id
    let cell = tableView.dequeueReusableCell(withIdentifier: "project", for: indexPath)
    cell.selectionStyle = .none
    var content = cell.defaultContentConfiguration()
    content.text = project.name
    content.secondaryText = project.description?.isEmpty == false ? project.description : project.slug
    content.textProperties.font = .preferredFont(forTextStyle: .headline)
    content.textProperties.color = SharePalette.text
    content.textProperties.numberOfLines = 2
    content.secondaryTextProperties.font = .preferredFont(forTextStyle: .subheadline)
    content.secondaryTextProperties.color = SharePalette.secondary
    content.secondaryTextProperties.numberOfLines = 2
    content.image = UIImage(systemName: selected ? "folder.fill" : "folder")
    content.imageProperties.tintColor = SharePalette.accentText
    content.imageProperties.maximumSize = CGSize(width: 28, height: 28)
    content.directionalLayoutMargins = NSDirectionalEdgeInsets(top: 18, leading: 16, bottom: 18, trailing: 12)
    cell.contentConfiguration = content
    var background = UIBackgroundConfiguration.clear()
    background.backgroundColor = selected ? UIColor(red: 238 / 255, green: 237 / 255, blue: 253 / 255, alpha: 1) : SharePalette.surface
    background.cornerRadius = 16
    background.backgroundInsets = NSDirectionalEdgeInsets(top: 4, leading: 0, bottom: 4, trailing: 0)
    background.strokeColor = selected ? SharePalette.accent : SharePalette.line
    background.strokeWidth = selected ? 1.5 : 1
    cell.backgroundConfiguration = background
    let check = UIImageView(image: UIImage(systemName: selected ? "checkmark.circle.fill" : "circle", withConfiguration: UIImage.SymbolConfiguration(pointSize: 24, weight: .medium)))
    check.tintColor = selected ? SharePalette.accent : UIColor(red: 163 / 255, green: 169 / 255, blue: 184 / 255, alpha: 1)
    check.sizeToFit()
    cell.accessoryView = check
    cell.accessibilityTraits = selected ? [.button, .selected] : [.button]
    cell.accessibilityLabel = "\(project.name), \(project.slug)"

    return cell
  }

  func tableView(_ tableView: UITableView, didSelectRowAt indexPath: IndexPath) {
    selectProject(projects[indexPath.row], remembered: false)
  }

  // Same path for a tap on the list and for the remembered project picked on launch.
  private func selectProject(_ project: Project, remembered: Bool) {
    selectedProject = project
    projectIsRemembered = remembered
    fetchLastUpload(for: project)
    UISelectionFeedbackGenerator().selectionChanged()
    table.isHidden = true
    signOutButton.isHidden = true
    if review.files.count == providers.count {
      showReview()
      return
    }
    isPreparing = true
    titleLabel.text = "Preparing your files…"
    detailLabel.text = "Then add feedback to each screenshot or recording."
    spinner.isHidden = false
    spinner.startAnimating()
    prepareProvider(at: 0)
  }

  @objc private func beginUpload() {
    guard selectedProject != nil, serverURL != nil, !token.isEmpty, !review.files.isEmpty, !isPreparing else { return }
    if appendToLastUpload && !appendLookup.canUpload {
      if appendLookup.failed, let project = selectedProject { fetchLastUpload(for: project) }
      return
    }
    let sizeLimit = 95 * 1024 * 1024
    if let oversized = review.files.first(where: { ((try? $0.url.resourceValues(forKeys: [.fileSizeKey]))?.fileSize ?? 0) > sizeLimit }) {
      showError("\(oversized.name) is larger than 95 MB. Trim the video before sharing it.", allowRetry: false)
      return
    }
    saveCurrentFeedback()
    view.endEditing(true)
    review.prepareForUpload()
    previewGeneration += 1
    previewGenerator?.cancelAllCGImageGeneration()
    reviewPanel.isHidden = true
    changeProjectButton.isHidden = true
    uploadButton.isEnabled = false
    uploadButton.isHidden = true
    footerSummary.isHidden = true
    table.isHidden = true
    signOutButton.isHidden = true
    titleLabel.text = "Uploading…"
    detailLabel.isHidden = false
    detailLabel.text = "Keeping your files and feedback together."
    uploadTotalBytes = max(1, review.files.reduce(Int64(0)) { $0 + Self.fileBytes($1.url) })
    uploadDoneBytes = 0
    uploadProgress.setProgress(0, animated: false)
    uploadProgress.isHidden = false
    spinner.isHidden = false
    spinner.startAnimating()
    do { try uploadChunk() } catch { showError("The feedback group could not be started.") }
  }

  private func prepareProvider(at index: Int) {
    guard !didClose else { return }
    guard index < providers.count else {
      isPreparing = false
      showReview()
      return
    }
    let provider = providers[index]
    let movies = provider.registeredTypeIdentifiers.filter { UTType($0)?.conforms(to: .movie) == true }
    let identifiers = movies.isEmpty ? provider.registeredTypeIdentifiers.filter { UTType($0)?.conforms(to: .image) == true } : movies
    loadRepresentation(provider: provider, identifiers: identifiers, typeIndex: 0, providerIndex: index)
  }

  private func loadRepresentation(provider: NSItemProvider, identifiers: [String], typeIndex: Int, providerIndex: Int) {
    guard typeIndex < identifiers.count else { showError("One of the shared files could not be read. No chunk was uploaded."); return }
    let identifier = identifiers[typeIndex]
    provider.loadFileRepresentation(forTypeIdentifier: identifier) { [weak self] fileURL, _ in
      guard let self else { return }
      guard let fileURL else {
        DispatchQueue.main.async {
          guard !self.didClose else { return }
          self.loadRepresentation(provider: provider, identifiers: identifiers, typeIndex: typeIndex + 1, providerIndex: providerIndex)
        }
        return
      }
      var fileName = provider.suggestedName?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
      if fileName.isEmpty { fileName = fileURL.lastPathComponent }
      if URL(fileURLWithPath: fileName).pathExtension.isEmpty, let ext = UTType(identifier)?.preferredFilenameExtension { fileName += ".\(ext)" }
      let copyURL = FileManager.default.temporaryDirectory.appendingPathComponent("uploadiny-\(UUID().uuidString).\(URL(fileURLWithPath: fileName).pathExtension)")
      do {
        // The provider's temporary URL is valid only during this callback.
        try FileManager.default.copyItem(at: fileURL, to: copyURL)
        let mime = UTType(identifier)?.preferredMIMEType ?? "application/octet-stream"
        let prepared: SharedFile
        let ownedURLs: [URL]
        if ["image/heic", "image/heif", "image/tiff"].contains(mime) {
          let jpeg = try self.jpegRepresentation(copyURL)
          try? FileManager.default.removeItem(at: copyURL)
          ownedURLs = [jpeg]
          let jpegName = URL(fileURLWithPath: fileName).deletingPathExtension().lastPathComponent + ".jpg"
          prepared = SharedFile(url: jpeg, name: jpegName, mime: "image/jpeg")
        } else {
          ownedURLs = [copyURL]
          prepared = SharedFile(url: copyURL, name: fileName, mime: mime)
        }
        DispatchQueue.main.async {
          guard !self.didClose else {
            for url in ownedURLs { try? FileManager.default.removeItem(at: url) }
            return
          }
          self.temporaryURLs.append(contentsOf: ownedURLs)
          self.review.files.append(prepared)
          self.prepareProvider(at: providerIndex + 1)
        }
      } catch {
        try? FileManager.default.removeItem(at: copyURL)
        DispatchQueue.main.async {
          guard !self.didClose else { return }
          self.showError("One of the files could not be prepared. No chunk was uploaded.")
        }
      }
    }
  }

  private func uploadChunk() throws {
    guard let base = serverURL, let project = selectedProject else { return }
    let url = base.appendingPathComponent("projects").appendingPathComponent(project.slug).appendingPathComponent("chunks/start")
    var request = authorizedRequest(url)
    request.httpMethod = "POST"
    request.setValue("application/json", forHTTPHeaderField: "Content-Type")
    // Append only to a last upload that was loaded for this project and is shown with the toggle on.
    let target = appendToLastUpload && lastUpload?.projectID == project.id ? lastUpload?.id : nil
    var body: [String: Any] = ["image_count": review.files.count]
    if let target { body["append_to"] = target }
    request.httpBody = try JSONSerialization.data(withJSONObject: body)
    appendingTo = target
    uploadedImageIDs = []
    request.timeoutInterval = 30
    startingDraft = true
    let session = secureSession(requestTimeout: 30, resourceTimeout: 30)
    uploads.dataTask(session: session, with: request) { [weak self] data, response, error in
      DispatchQueue.main.async {
        guard let self else { return }
        self.startingDraft = false
        if let response = response as? HTTPURLResponse, response.statusCode == 401 {
          self.requireSignIn("Your iPhone sign-in is no longer valid. Sign in again.")
          return
        }
        guard error == nil, let response = response as? HTTPURLResponse, response.statusCode == 201,
              let data, let draft = try? JSONDecoder().decode(DraftResult.self, from: data) else {
          if self.didClose { self.finishClosing(); return }
          self.showError("The feedback group could not be started. Check your connection and try again.")
          return
        }
        self.draftID = draft.id
        if self.didClose { self.cancelDraft { self.finishClosing() }; return }
        self.uploadFile(at: 0)
      }
    }.resume()
  }

  private func authorizedRequest(_ url: URL) -> URLRequest {
    var request = URLRequest(url: url)
    request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
    request.setValue("application/json", forHTTPHeaderField: "Accept")
    request.cachePolicy = .reloadIgnoringLocalCacheData
    request.timeoutInterval = 600
    return request
  }

  private func uploadFile(at index: Int) {
    guard !didClose, let base = serverURL, let draftID else { return }
    guard index < review.files.count else { completeChunk(); return }
    let file = review.files[index]
    currentFileBytes = Self.fileBytes(file.url)
    currentFileIndex = index
    showUploadProgress(index: index, fileFraction: 0)
    let boundary = "Uploadiny-\(UUID().uuidString)"
    uploads.stage(file: file, boundary: boundary) { [weak self] result in
      guard let self else { if case .success(let url) = result { try? FileManager.default.removeItem(at: url) }; return }
      guard !self.didClose else { if case .success(let url) = result { try? FileManager.default.removeItem(at: url) }; return }
      guard case .success(let bodyURL) = result else { self.showError("A file could not be prepared. The incomplete group was not published."); return }
      self.temporaryURLs.append(bodyURL)
      var request = self.authorizedRequest(base.appendingPathComponent("chunks").appendingPathComponent(draftID).appendingPathComponent("images"))
      request.httpMethod = "POST"
      request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
      let session = self.secureSession()
      let task = self.uploads.uploadTask(session: session, with: request, fromFile: bodyURL) { [weak self] data, response, error in
        DispatchQueue.main.async {
          guard let self, !self.didClose else { return }
          try? FileManager.default.removeItem(at: bodyURL)
          guard error == nil else { self.showError("Connection interrupted. The incomplete group was not published."); return }
          if let response = response as? HTTPURLResponse, response.statusCode == 401 {
            self.requireSignIn("Your iPhone sign-in is no longer valid. Sign in again.")
            return
          }
          guard let response = response as? HTTPURLResponse, response.statusCode == 201 else {
            self.showError(self.serverError(data, response))
            return
          }
          guard let data, let result = try? JSONDecoder().decode(AppendResult.self, from: data),
                result.confirms(file, receivedCount: index + 1), let image = result.image else {
            self.showError("Your feedback could not be confirmed. This group was not published. Your notes are still here.")
            return
          }
          self.uploadedImageIDs.append(image.id)
          self.uploadDoneBytes += self.currentFileBytes
          self.uploadFile(at: index + 1)
        }
      }
      self.currentUploadTask = task
      task.resume()
    }
  }

  private func completeChunk() {
    guard let base = serverURL, let draftID else { return }
    var request = authorizedRequest(base.appendingPathComponent("chunks").appendingPathComponent(draftID).appendingPathComponent("complete"))
    request.httpMethod = "POST"
    let session = secureSession()
    uploads.dataTask(session: session, with: request) { [weak self] data, response, error in
      DispatchQueue.main.async {
        guard let self, !self.didClose else { return }
        if let response = response as? HTTPURLResponse, response.statusCode == 401 {
          self.requireSignIn("Your iPhone sign-in is no longer valid. Sign in again.")
          return
        }
        guard error == nil, let response = response as? HTTPURLResponse, response.statusCode == 200,
              let data, let result = try? JSONDecoder().decode(ChunkResult.self, from: data),
              self.uploadedImageIDs.count == self.review.files.count,
              Set(self.uploadedImageIDs).isSubset(of: result.images.map(\.id)),
              // A merged last upload also holds its earlier files.
              self.appendingTo != nil || result.images.count == self.review.files.count else {
          self.showError("The final response was unavailable. Check your project in the workspace before sharing again; it may already be uploaded.", allowRetry: false)
          return
        }
        let shared = self.uploadedImageIDs.compactMap { id in result.images.first { $0.id == id } }
        let merged = self.appendingTo != nil && result.id == self.appendingTo
        if let slug = self.selectedProject?.slug { UploadinyLastProjectStore.write(slug) }
        self.draftID = nil
        let thumbnail = self.review.files.first.flatMap { self.imagePreview($0.url) }
        self.cleanupFiles()
        self.spinner.stopAnimating()
      self.uploadProgress.isHidden = true
        self.spinner.isHidden = true
        let projectName = self.selectedProject?.name ?? "project"
        self.titleLabel.text = merged ? "Added to the last upload in \(projectName)" : "Uploaded to \(projectName)"
        let sharedSummary = shared.prefix(3).map { $0.name }.joined(separator: "\n") + (shared.count > 3 ? "\n+ \(shared.count - 3) more files" : "")
        self.detailLabel.text = sharedSummary + "\nClosing in 5… tap to close now"
        self.titleLabel.textAlignment = .center
        self.detailLabel.textAlignment = .center
        self.closeButton.accessibilityLabel = "Done"
        self.closeButton.configuration?.image = UIImage(systemName: "checkmark", withConfiguration: UIImage.SymbolConfiguration(pointSize: 14, weight: .semibold))
        self.preview.image = thumbnail
        self.preview.isHidden = thumbnail == nil
        self.canDismiss = true
        self.preferredContentSize = CGSize(width: 0, height: thumbnail == nil ? 300 : 680)
        self.startAutoClose(summary: sharedSummary)
      }
    }.resume()
  }

  private func serverError(_ data: Data?, _ response: URLResponse?) -> String {
    if let data, let result = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
      if let errors = result["errors"] as? [String: [String]], let message = errors.sorted(by: { $0.key < $1.key }).first?.value.first { return message }
      if let message = result["message"] as? String { return message }
    }
    return "A file could not be uploaded. The incomplete group was not published."
  }

  private func cancelDraft(completion: @escaping () -> Void = {}) {
    guard let base = serverURL, let draftID else { completion(); return }
    self.draftID = nil
    var request = authorizedRequest(base.appendingPathComponent("chunks").appendingPathComponent(draftID))
    request.httpMethod = "DELETE"
    request.timeoutInterval = 10
    uploads.dataTask(session: secureSession(requestTimeout: 10, resourceTimeout: 10), with: request) { _, _, _ in
      DispatchQueue.main.async { completion() }
    }.resume()
  }

  private func jpegRepresentation(_ sourceURL: URL) throws -> URL {
    let targetURL = FileManager.default.temporaryDirectory.appendingPathComponent("uploadiny-\(UUID().uuidString).jpg")
    guard let source = CGImageSourceCreateWithURL(sourceURL as CFURL, nil),
          let destination = CGImageDestinationCreateWithURL(targetURL as CFURL, UTType.jpeg.identifier as CFString, 1, nil) else { throw UploadError.cannotReadFile }
    CGImageDestinationAddImageFromSource(destination, source, 0, [kCGImageDestinationLossyCompressionQuality: 0.95] as CFDictionary)
    guard CGImageDestinationFinalize(destination) else {
      try? FileManager.default.removeItem(at: targetURL)
      throw UploadError.cannotReadFile
    }
    return targetURL
  }

  private func imagePreview(_ url: URL, maxPixelSize: Int = 1280) -> UIImage? {
    guard let source = CGImageSourceCreateWithURL(url as CFURL, nil) else { return nil }
    let options: [CFString: Any] = [kCGImageSourceCreateThumbnailFromImageAlways: true, kCGImageSourceCreateThumbnailWithTransform: true, kCGImageSourceThumbnailMaxPixelSize: maxPixelSize]
    guard let image = CGImageSourceCreateThumbnailAtIndex(source, 0, options as CFDictionary) else { return nil }
    return UIImage(cgImage: image)
  }

  private func showError(_ text: String, allowRetry: Bool = true) {
    DispatchQueue.main.async {
      guard !self.didClose else { return }
      self.isPreparing = false
      self.previewGeneration += 1
      self.previewGenerator?.cancelAllCGImageGeneration()
      self.spinner.stopAnimating()
      self.uploadProgress.isHidden = true
      self.spinner.isHidden = true
      self.table.isHidden = true
      self.reviewPanel.isHidden = true
      self.changeProjectButton.isHidden = true
      self.uploadButton.isHidden = true
      self.footerSummary.isHidden = true
      self.detailLabel.isHidden = false
      self.titleLabel.text = "Upload could not finish"
      self.detailLabel.text = text
      self.cancelDraft {
        guard !self.didClose, self.review.files.count == self.providers.count, !self.review.files.isEmpty else { return }
        self.reviewPanel.isHidden = false
        self.changeProjectButton.isHidden = !allowRetry
        self.showAsset()
        guard allowRetry else { return }
        self.uploadButton.configuration?.title = "Try upload again"
        self.footerSummary.isHidden = false
        self.uploadButton.isEnabled = true
        self.uploadButton.alpha = 1
        self.uploadButton.isHidden = false
      }
    }
  }

  private func cleanupFiles() {
    for url in temporaryURLs { try? FileManager.default.removeItem(at: url) }
    temporaryURLs.removeAll()
  }

  private func startAutoClose(summary: String, seconds: Int = 5) {
    autoCloseTimer?.invalidate()
    var remaining = seconds
    let timer = Timer(timeInterval: 1, repeats: true) { [weak self] timer in
      guard let self, !self.didClose, self.canDismiss else { timer.invalidate(); return }
      remaining -= 1
      if remaining <= 0 {
        timer.invalidate()
        self.closeExtension()
        return
      }
      self.detailLabel.text = summary + "\nClosing in \(remaining)… tap to close now"
    }
    autoCloseTimer = timer
    RunLoop.main.add(timer, forMode: .common)
  }

  @objc private func closeExtension() {
    guard !didClose else { return }
    autoCloseTimer?.invalidate()
    autoCloseTimer = nil
    didClose = true
    view.endEditing(true)
    previewGenerator?.cancelAllCGImageGeneration()
    closeButton.isEnabled = false
    detailLabel.isHidden = false
    detailLabel.text = "Closing…"
    if startingDraft { return }
    uploads.cancel()
    cancelDraft { self.finishClosing() }
  }

  private func finishClosing() {
    uploads.cancel()
    cleanupFiles()
    extensionContext?.completeRequest(returningItems: nil)
  }

  @objc private func dismissAfterSuccess() {
    guard canDismiss else { return }
    closeExtension()
  }
}

private enum SharePalette {
  static let canvas = UIColor(red: 238 / 255, green: 240 / 255, blue: 244 / 255, alpha: 1)
  static let surface = UIColor(red: 255 / 255, green: 255 / 255, blue: 255 / 255, alpha: 1)
  static let accent = UIColor(red: 81 / 255, green: 70 / 255, blue: 229 / 255, alpha: 1)
  static let accentText = UIColor(red: 67 / 255, green: 56 / 255, blue: 202 / 255, alpha: 1)
  static let text = UIColor(red: 14 / 255, green: 21 / 255, blue: 37 / 255, alpha: 1)
  static let secondary = UIColor(red: 91 / 255, green: 99 / 255, blue: 117 / 255, alpha: 1)
  static let line = UIColor(red: 227 / 255, green: 230 / 255, blue: 238 / 255, alpha: 1)
  static let mint = UIColor(red: 31 / 255, green: 138 / 255, blue: 87 / 255, alpha: 1)
}

private final class ShareAssetCell: UICollectionViewCell {
  var representedIndex: Int?
  let imageView = UIImageView()
  private let numberLabel = UILabel()
  private let noteBadge = UIImageView(image: UIImage(systemName: "checkmark.circle.fill"))

  override init(frame: CGRect) {
    super.init(frame: frame)
    contentView.backgroundColor = SharePalette.surface
    contentView.layer.cornerRadius = 12
    contentView.layer.borderWidth = 2
    contentView.clipsToBounds = true
    imageView.contentMode = .scaleAspectFill
    imageView.tintColor = SharePalette.secondary
    imageView.clipsToBounds = true
    imageView.layer.cornerRadius = 8
    numberLabel.font = .preferredFont(forTextStyle: .caption2)
    numberLabel.textColor = .white
    numberLabel.backgroundColor = SharePalette.text.withAlphaComponent(0.72)
    numberLabel.textAlignment = .center
    numberLabel.layer.cornerRadius = 6
    numberLabel.clipsToBounds = true
    noteBadge.tintColor = SharePalette.mint
    noteBadge.backgroundColor = SharePalette.canvas
    noteBadge.layer.cornerRadius = 9
    for child in [imageView, numberLabel, noteBadge] {
      child.translatesAutoresizingMaskIntoConstraints = false
      contentView.addSubview(child)
    }
    NSLayoutConstraint.activate([
      imageView.topAnchor.constraint(equalTo: contentView.topAnchor, constant: 4),
      imageView.leadingAnchor.constraint(equalTo: contentView.leadingAnchor, constant: 4),
      imageView.trailingAnchor.constraint(equalTo: contentView.trailingAnchor, constant: -4),
      imageView.bottomAnchor.constraint(equalTo: contentView.bottomAnchor, constant: -4),
      numberLabel.leadingAnchor.constraint(equalTo: contentView.leadingAnchor, constant: 6),
      numberLabel.bottomAnchor.constraint(equalTo: contentView.bottomAnchor, constant: -6),
      numberLabel.widthAnchor.constraint(equalToConstant: 20),
      numberLabel.heightAnchor.constraint(equalToConstant: 20),
      noteBadge.trailingAnchor.constraint(equalTo: contentView.trailingAnchor, constant: -5),
      noteBadge.topAnchor.constraint(equalTo: contentView.topAnchor, constant: 5),
      noteBadge.widthAnchor.constraint(equalToConstant: 18),
      noteBadge.heightAnchor.constraint(equalToConstant: 18),
    ])
    isAccessibilityElement = true
  }

  required init?(coder: NSCoder) { fatalError("init(coder:) has not been implemented") }

  override func prepareForReuse() {
    super.prepareForReuse()
    representedIndex = nil
    imageView.image = nil
  }

  func configure(number: Int, file: SharedFile, selected: Bool) {
    numberLabel.text = "\(number)"
    imageView.image = UIImage(systemName: file.mime.hasPrefix("video/") ? "video.fill" : "photo")
    imageView.contentMode = file.mime.hasPrefix("video/") ? .center : .scaleAspectFill
    contentView.layer.borderColor = (selected ? SharePalette.accentText : SharePalette.line).cgColor
    accessibilityTraits = selected ? [.button, .selected] : [.button]
    accessibilityLabel = "Asset \(number), \(file.name)"
    updateNote(!file.comments.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
  }

  func updateNote(_ hasNote: Bool) {
    noteBadge.isHidden = !hasNote
    accessibilityValue = hasNote ? "Feedback added" : "No feedback"
  }
}

private struct ProjectList: Decodable { let projects: [Project] }
private struct Project: Decodable { let id: Int; let name: String; let slug: String; let description: String? }
private struct ShareAppendDecision {
  private var projectID: Int?
  private var generation = 0
  private(set) var loading = false
  private(set) var failed = false
  var canUpload: Bool { !loading && !failed && projectID != nil }

  mutating func begin(projectID: Int, generation: Int) {
    self.projectID = projectID
    self.generation = generation
    loading = true
    failed = false
  }

  mutating func resolve(projectID: Int, generation: Int, success: Bool) {
    guard self.projectID == projectID, self.generation == generation else { return }
    loading = false
    failed = !success
  }
}

private final class ShareUploadCoordinator {
  private let lock = NSLock()
  private var sessions: [ObjectIdentifier: URLSession] = [:]
  private let staging = OperationQueue()
  private var stagingGeneration = 0
  private let configuration: () -> URLSessionConfiguration

  init(configuration: @escaping () -> URLSessionConfiguration = { .ephemeral }) {
    self.configuration = configuration
    staging.maxConcurrentOperationCount = 1
    staging.qualityOfService = .utility
  }

  func session(delegate: URLSessionDelegate, requestTimeout: TimeInterval, resourceTimeout: TimeInterval) -> URLSession {
    let configuration = self.configuration()
    configuration.timeoutIntervalForRequest = requestTimeout
    configuration.timeoutIntervalForResource = resourceTimeout
    configuration.urlCache = nil
    configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
    let session = URLSession(configuration: configuration, delegate: delegate, delegateQueue: nil)
    lock.lock(); sessions[ObjectIdentifier(session)] = session; lock.unlock()
    return session
  }

  func dataTask(session: URLSession, with request: URLRequest, completion: @escaping (Data?, URLResponse?, Error?) -> Void) -> URLSessionDataTask {
    return session.dataTask(with: request) { [self] data, response, error in
      finish(session)
      completion(data, response, error)
    }
  }

  func uploadTask(session: URLSession, with request: URLRequest, fromFile url: URL, completion: @escaping (Data?, URLResponse?, Error?) -> Void) -> URLSessionUploadTask {
    return session.uploadTask(with: request, fromFile: url) { [self] data, response, error in
      finish(session)
      completion(data, response, error)
    }
  }

  func finish(_ session: URLSession) {
    session.finishTasksAndInvalidate()
    lock.lock(); sessions.removeValue(forKey: ObjectIdentifier(session)); lock.unlock()
  }

  func cancel() {
    staging.cancelAllOperations()
    lock.lock(); stagingGeneration += 1; let current = Array(sessions.values); sessions.removeAll(); lock.unlock()
    for session in current { session.invalidateAndCancel() }
  }

  func stage(file: SharedFile, boundary: String, completion: @escaping (Result<URL, Error>) -> Void) {
    lock.lock(); let generation = stagingGeneration; lock.unlock()
    staging.addOperation { [self] in
      let url = FileManager.default.temporaryDirectory.appendingPathComponent("uploadiny-\(UUID().uuidString).multipart")
      let result: Result<URL, Error>
      do {
        try file.writeMultipart(to: url, boundary: boundary, cancelled: { self.stagingCancelled(generation) })
        result = .success(url)
      } catch {
        try? FileManager.default.removeItem(at: url)
        result = .failure(error)
      }
      DispatchQueue.main.async {
        if self.stagingCancelled(generation) {
          try? FileManager.default.removeItem(at: url)
          completion(.failure(URLError(.cancelled)))
        } else { completion(result) }
      }
    }
  }

  private func stagingCancelled(_ generation: Int) -> Bool {
    lock.lock(); defer { lock.unlock() }
    return stagingGeneration != generation
  }
}

private struct SharedFile {
  let url: URL
  let name: String
  let mime: String
  var comments = ""

  func writeMultipart(to bodyURL: URL, boundary: String, cancelled: () -> Bool = { false }) throws {
    if cancelled() { throw URLError(.cancelled) }
    FileManager.default.createFile(atPath: bodyURL.path, contents: nil)
    let output = try FileHandle(forWritingTo: bodyURL)
    defer { try? output.close() }
    let safeName = name.replacingOccurrences(of: "\\", with: "_").replacingOccurrences(of: "\"", with: "_").replacingOccurrences(of: "\r", with: "_").replacingOccurrences(of: "\n", with: "_")
    let header = "--\(boundary)\r\nContent-Disposition: form-data; name=\"comments\"\r\n\r\n\(comments)\r\n--\(boundary)\r\nContent-Disposition: form-data; name=\"file\"; filename=\"\(safeName)\"\r\nContent-Type: \(mime)\r\n\r\n"
    try output.write(contentsOf: Data(header.utf8))
    guard let input = InputStream(url: url) else { throw UploadError.cannotReadFile }
    input.open()
    defer { input.close() }
    var buffer = [UInt8](repeating: 0, count: 64 * 1024)
    while input.hasBytesAvailable {
      if cancelled() { throw URLError(.cancelled) }
      let count = input.read(&buffer, maxLength: buffer.count)
      if count < 0 { throw UploadError.cannotReadFile }
      if count == 0 { break }
      try output.write(contentsOf: Data(buffer[0..<count]))
    }
    try output.write(contentsOf: Data("\r\n--\(boundary)--\r\n".utf8))
  }
}

private struct ShareReview {
  var files: [SharedFile] = []
  private(set) var selectedIndex = 0

  mutating func select(_ index: Int) {
    guard files.indices.contains(index) else { return }
    selectedIndex = index
  }

  mutating func updateComments(_ comments: String) {
    guard files.indices.contains(selectedIndex) else { return }
    files[selectedIndex].comments = comments
  }

  mutating func prepareForUpload() {
    for index in files.indices {
      files[index].comments = files[index].comments.trimmingCharacters(in: .whitespacesAndNewlines)
    }
  }
}

private struct AppendResult: Decodable {
  let received_images: Int
  let image: UploadedImage?

  func confirms(_ file: SharedFile, receivedCount: Int) -> Bool {
    received_images == receivedCount && image?.comments == file.comments
  }
}
private struct ChunkResult: Decodable { let id: String; let images: [UploadedImage] }
private struct UploadedImage: Decodable { let id: String; let name: String; let comments: String }
private enum UploadError: Error { case cannotReadFile }

private struct DraftResult: Decodable { let id: String }

private struct DeviceTokenResult: Decodable { let token: String }

private struct LastUploadResult: Decodable { let chunk: LastUploadChunk? }
private struct LastUploadChunk: Decodable { let id: String; let completed_at: String; let file_count: Int }
private struct LastUploadSummary { let projectID: Int; let id: String; let completedAt: Date; let fileCount: Int }

private enum UploadinyKeychainStore {
  private static let service = "test.uploadiny.app.share"

  private static func query(account: String) -> [CFString: Any] {
    [kSecClass: kSecClassGenericPassword, kSecAttrService: service, kSecAttrAccount: account]
  }

  static func read(account: String) -> Data? {
    var item: CFTypeRef?
    var attributes = query(account: account)
    attributes[kSecReturnData] = true
    attributes[kSecMatchLimit] = kSecMatchLimitOne
    guard SecItemCopyMatching(attributes as CFDictionary, &item) == errSecSuccess else { return nil }
    return item as? Data
  }

  static func readString(account: String) -> String? {
    guard let data = read(account: account), let value = String(data: data, encoding: .utf8), !value.isEmpty else { return nil }
    return value
  }

  static func write(_ data: Data, account: String) -> Bool {
    delete(account: account)
    var attributes = query(account: account)
    attributes[kSecAttrAccessible] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly
    attributes[kSecValueData] = data
    return SecItemAdd(attributes as CFDictionary, nil) == errSecSuccess
  }

  static func delete(account: String) {
    SecItemDelete(query(account: account) as CFDictionary)
  }
}

private enum UploadinyDeviceTokenStore {
  private static let account = "uploadiny-device-token"

  static func read() -> String? { UploadinyKeychainStore.readString(account: account) }

  static func write(_ token: String) throws {
    guard UploadinyKeychainStore.write(Data(token.utf8), account: account) else {
      throw UploadinyDeviceTokenStoreError.unavailable
    }
  }

  static func delete() { UploadinyKeychainStore.delete(account: account) }
}

private enum UploadinyDeviceTokenStoreError: Error { case unavailable }

// Remembers the "Add to the last upload" choice as "1" or "0" in its own item. Absent means on.
private enum UploadinyAppendPreferenceStore {
  private static let account = "append-to-last-upload"

  static func read() -> Bool {
    guard let data = UploadinyKeychainStore.read(account: account) else { return true }
    return String(data: data, encoding: .utf8) != "0"
  }

  @discardableResult
  static func write(_ enabled: Bool) -> Bool {
    UploadinyKeychainStore.write(Data((enabled ? "1" : "0").utf8), account: account)
  }
}

// Remembers the slug of the project used by the last successful upload, in its own item.
private enum UploadinyLastProjectStore {
  private static let account = "last-project-slug"

  static func read() -> String? {
    UploadinyKeychainStore.readString(account: account)
  }

  @discardableResult
  static func write(_ slug: String) -> Bool {
    UploadinyKeychainStore.write(Data(slug.utf8), account: account)
  }
}

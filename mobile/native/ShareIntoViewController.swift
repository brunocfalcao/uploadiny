import ImageIO
import Security
import UIKit
import UniformTypeIdentifiers

final class ShareIntoViewController: UIViewController, UITableViewDataSource, UITableViewDelegate, URLSessionTaskDelegate {
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
  private var projects: [Project] = []
  private var selectedProject: Project?
  private var providers: [NSItemProvider] = []
  private var preparedFiles: [SharedFile] = []
  private var uploadSession: URLSession?
  private var temporaryURLs: [URL] = []
  private var didStart = false
  private var canDismiss = false
  private var didClose = false
  private var startingDraft = false
  private var draftID: String?
  private var serverURL: URL?
  private var token = ""

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
    preferredContentSize = CGSize(width: 0, height: 620)
    view.backgroundColor = UIColor(red: 7 / 255, green: 11 / 255, blue: 24 / 255, alpha: 1)
    titleLabel.text = "Choose a project"
    titleLabel.font = .systemFont(ofSize: 24, weight: .bold)
    titleLabel.textColor = .white
    titleLabel.textAlignment = .left
    titleLabel.numberOfLines = 2
    titleLabel.adjustsFontForContentSizeCategory = true
    detailLabel.text = "Loading your projects…"
    detailLabel.font = .systemFont(ofSize: 14)
    detailLabel.textColor = UIColor(red: 168 / 255, green: 176 / 255, blue: 205 / 255, alpha: 1)
    detailLabel.numberOfLines = 4
    detailLabel.textAlignment = .left
    detailLabel.adjustsFontForContentSizeCategory = true
    emptyProjectIcon.image = UIImage(systemName: "folder.badge.plus", withConfiguration: UIImage.SymbolConfiguration(pointSize: 52, weight: .medium))
    emptyProjectIcon.tintColor = UIColor(red: 132 / 255, green: 148 / 255, blue: 255 / 255, alpha: 1)
    emptyProjectIcon.contentMode = .scaleAspectFit
    emptyProjectIcon.isAccessibilityElement = false
    emptyProjectIcon.isHidden = true
    preview.contentMode = .scaleAspectFit
    preview.clipsToBounds = true
    preview.layer.cornerRadius = 20
    preview.backgroundColor = UIColor(red: 13 / 255, green: 20 / 255, blue: 38 / 255, alpha: 1)
    preview.isHidden = true
    table.backgroundColor = .clear
    table.separatorStyle = .none
    table.indicatorStyle = .white
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
    signInButton.setTitle("Sign in securely", for: .normal)
    var signInConfiguration = UIButton.Configuration.filled()
    signInConfiguration.baseBackgroundColor = UIColor(red: 79 / 255, green: 99 / 255, blue: 234 / 255, alpha: 1)
    signInConfiguration.baseForegroundColor = .white
    signInConfiguration.cornerStyle = .large
    signInConfiguration.image = UIImage(systemName: "lock.fill")
    signInConfiguration.imagePadding = 10
    signInConfiguration.contentInsets = NSDirectionalEdgeInsets(top: 16, leading: 20, bottom: 16, trailing: 20)
    signInButton.configuration = signInConfiguration
    signInButton.addTarget(self, action: #selector(beginSignIn), for: .touchUpInside)
    signOutButton.setTitle("Remove this iPhone sign-in", for: .normal)
    signOutButton.setTitleColor(UIColor(red: 220 / 255, green: 228 / 255, blue: 250 / 255, alpha: 1), for: .normal)
    signOutButton.titleLabel?.font = .systemFont(ofSize: 15, weight: .semibold)
    signOutButton.addTarget(self, action: #selector(signOut), for: .touchUpInside)
    signOutButton.isHidden = true
    uploadButton.setTitle("Upload files", for: .normal)
    uploadButton.setTitleColor(.white, for: .normal)
    uploadButton.backgroundColor = UIColor(red: 51 / 255, green: 72 / 255, blue: 216 / 255, alpha: 1)
    var uploadConfiguration = UIButton.Configuration.filled()
    uploadConfiguration.baseBackgroundColor = UIColor(red: 79 / 255, green: 99 / 255, blue: 234 / 255, alpha: 1)
    uploadConfiguration.baseForegroundColor = .white
    uploadConfiguration.cornerStyle = .large
    uploadConfiguration.image = UIImage(systemName: "arrow.up.doc.fill")
    uploadConfiguration.imagePadding = 10
    uploadConfiguration.contentInsets = NSDirectionalEdgeInsets(top: 16, leading: 20, bottom: 16, trailing: 20)
    uploadButton.configuration = uploadConfiguration
    uploadButton.titleLabel?.font = .systemFont(ofSize: 16, weight: .bold)
    uploadButton.isEnabled = false
    uploadButton.alpha = 0.5
    uploadButton.isHidden = true
    uploadButton.addTarget(self, action: #selector(beginUpload), for: .touchUpInside)
    closeButton.setTitle("Close", for: .normal)
    var closeConfiguration = UIButton.Configuration.gray()
    closeConfiguration.baseBackgroundColor = UIColor(red: 28 / 255, green: 37 / 255, blue: 60 / 255, alpha: 1)
    closeConfiguration.baseForegroundColor = UIColor(red: 220 / 255, green: 228 / 255, blue: 250 / 255, alpha: 1)
    closeConfiguration.cornerStyle = .large
    closeConfiguration.image = UIImage(systemName: "xmark", withConfiguration: UIImage.SymbolConfiguration(pointSize: 12, weight: .semibold))
    closeConfiguration.imagePadding = 8
    closeConfiguration.contentInsets = NSDirectionalEdgeInsets(top: 14, leading: 20, bottom: 14, trailing: 20)
    closeButton.configuration = closeConfiguration
    closeButton.addTarget(self, action: #selector(closeExtension), for: .touchUpInside)
    spinner.color = .white
    spinner.startAnimating()
    let stack = UIStackView(arrangedSubviews: [emptyProjectIcon, titleLabel, detailLabel, emailField, passwordField, signInButton, preview, spinner, table, uploadButton, signOutButton, closeButton])
    stack.axis = .vertical
    stack.alignment = .fill
    stack.spacing = 18
    stack.translatesAutoresizingMaskIntoConstraints = false
    let scroll = UIScrollView()
    scroll.translatesAutoresizingMaskIntoConstraints = false
    scroll.indicatorStyle = .white
    scroll.alwaysBounceVertical = false
    view.addSubview(scroll)
    scroll.addSubview(stack)
    let emptyIconHeight = emptyProjectIcon.heightAnchor.constraint(equalToConstant: 64)
    emptyIconHeight.priority = .defaultHigh
    tableHeight = table.heightAnchor.constraint(equalToConstant: 320)
    tableHeight.priority = .defaultHigh
    previewHeight = preview.heightAnchor.constraint(equalToConstant: 360)
    previewHeight.priority = .defaultHigh
    let uploadHeight = uploadButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 56)
    uploadHeight.priority = .defaultHigh
    let closeHeight = closeButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 48)
    closeHeight.priority = .defaultHigh
    let emailHeight = emailField.heightAnchor.constraint(greaterThanOrEqualToConstant: 52)
    emailHeight.priority = .defaultHigh
    let passwordHeight = passwordField.heightAnchor.constraint(greaterThanOrEqualToConstant: 52)
    passwordHeight.priority = .defaultHigh
    let signInHeight = signInButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 56)
    signInHeight.priority = .defaultHigh
    NSLayoutConstraint.activate([
      scroll.leadingAnchor.constraint(equalTo: view.leadingAnchor),
      scroll.trailingAnchor.constraint(equalTo: view.trailingAnchor),
      scroll.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor),
      scroll.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor),
      stack.leadingAnchor.constraint(equalTo: scroll.contentLayoutGuide.leadingAnchor, constant: 24),
      stack.trailingAnchor.constraint(equalTo: scroll.contentLayoutGuide.trailingAnchor, constant: -24),
      stack.topAnchor.constraint(equalTo: scroll.contentLayoutGuide.topAnchor, constant: 28),
      stack.bottomAnchor.constraint(equalTo: scroll.contentLayoutGuide.bottomAnchor, constant: -24),
      stack.widthAnchor.constraint(equalTo: scroll.frameLayoutGuide.widthAnchor, constant: -48),
      tableHeight, emptyIconHeight, previewHeight, uploadHeight, closeHeight,
      emailHeight, passwordHeight, signInHeight,
    ])
    let gesture = UITapGestureRecognizer(target: self, action: #selector(dismissAfterSuccess))
    gesture.cancelsTouchesInView = false
    view.addGestureRecognizer(gesture)
  }

  private func configureCredentialField(_ field: UITextField, placeholder: String, secure: Bool) {
    field.placeholder = placeholder
    field.textColor = .white
    field.tintColor = UIColor(red: 166 / 255, green: 182 / 255, blue: 255 / 255, alpha: 1)
    field.backgroundColor = UIColor(red: 16 / 255, green: 24 / 255, blue: 43 / 255, alpha: 1)
    field.layer.cornerRadius = 14
    field.layer.borderWidth = 1
    field.layer.borderColor = UIColor(red: 37 / 255, green: 49 / 255, blue: 76 / 255, alpha: 1).cgColor
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
    session.dataTask(with: request) { [weak self] data, response, error in
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
          self.preferredContentSize = CGSize(width: 0, height: 360)
          return
        }
        self.titleLabel.text = "Choose a project"
        self.titleLabel.textAlignment = .left
        self.detailLabel.textAlignment = .left
        self.detailLabel.text = "\(self.providers.count) file\(self.providers.count == 1 ? "" : "s") will stay together in one feedback group."
        self.uploadButton.setTitle("Upload \(self.providers.count) file\(self.providers.count == 1 ? "" : "s")", for: .normal)
        self.tableHeight.constant = min(320, CGFloat(self.projects.count) * 88)
        self.table.isHidden = false
        self.uploadButton.isHidden = false
        self.table.reloadData()
      }
    }.resume()
  }

  private func showSignIn(_ message: String = "Sign in once to give this iPhone limited upload access. Your password is never stored.") {
    DispatchQueue.main.async {
      self.spinner.stopAnimating()
      self.spinner.isHidden = true
      self.emptyProjectIcon.isHidden = true
      self.preview.isHidden = true
      self.table.isHidden = true
      self.uploadButton.isHidden = true
      self.signOutButton.isHidden = true
      self.emailField.isHidden = false
      self.passwordField.isHidden = false
      self.signInButton.isHidden = false
      self.signInButton.isEnabled = true
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
    session.dataTask(with: request) { [weak self] data, response, error in
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
    cleanupFiles()
    showSignIn(message)
  }

  private func secureSession(requestTimeout: TimeInterval = 600, resourceTimeout: TimeInterval = 600) -> URLSession {
    let configuration = URLSessionConfiguration.ephemeral
    configuration.timeoutIntervalForRequest = requestTimeout
    configuration.timeoutIntervalForResource = resourceTimeout
    configuration.urlCache = nil
    configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
    let session = URLSession(configuration: configuration, delegate: self, delegateQueue: nil)
    uploadSession = session
    return session
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
    content.textProperties.color = .white
    content.textProperties.numberOfLines = 2
    content.secondaryTextProperties.font = .preferredFont(forTextStyle: .subheadline)
    content.secondaryTextProperties.color = UIColor(red: 168 / 255, green: 180 / 255, blue: 210 / 255, alpha: 1)
    content.secondaryTextProperties.numberOfLines = 2
    content.image = UIImage(systemName: selected ? "folder.fill" : "folder")
    content.imageProperties.tintColor = UIColor(red: 166 / 255, green: 182 / 255, blue: 255 / 255, alpha: 1)
    content.imageProperties.maximumSize = CGSize(width: 28, height: 28)
    content.directionalLayoutMargins = NSDirectionalEdgeInsets(top: 18, leading: 16, bottom: 18, trailing: 12)
    cell.contentConfiguration = content
    var background = UIBackgroundConfiguration.clear()
    background.backgroundColor = selected ? UIColor(red: 33 / 255, green: 46 / 255, blue: 87 / 255, alpha: 1) : UIColor(red: 16 / 255, green: 24 / 255, blue: 43 / 255, alpha: 1)
    background.cornerRadius = 16
    background.backgroundInsets = NSDirectionalEdgeInsets(top: 4, leading: 0, bottom: 4, trailing: 0)
    background.strokeColor = selected ? UIColor(red: 132 / 255, green: 148 / 255, blue: 255 / 255, alpha: 1) : UIColor(red: 37 / 255, green: 49 / 255, blue: 76 / 255, alpha: 1)
    background.strokeWidth = selected ? 1.5 : 1
    cell.backgroundConfiguration = background
    let check = UIImageView(image: UIImage(systemName: selected ? "checkmark.circle.fill" : "circle", withConfiguration: UIImage.SymbolConfiguration(pointSize: 24, weight: .medium)))
    check.tintColor = selected ? UIColor(red: 166 / 255, green: 182 / 255, blue: 255 / 255, alpha: 1) : UIColor(red: 91 / 255, green: 107 / 255, blue: 143 / 255, alpha: 1)
    check.sizeToFit()
    cell.accessoryView = check
    cell.accessibilityTraits = selected ? [.button, .selected] : [.button]
    cell.accessibilityLabel = "\(project.name), \(project.slug)"

    return cell
  }

  func tableView(_ tableView: UITableView, didSelectRowAt indexPath: IndexPath) {
    selectedProject = projects[indexPath.row]
    uploadButton.isEnabled = true
    uploadButton.alpha = 1
    table.reloadData()
    let feedback = UISelectionFeedbackGenerator()
    feedback.selectionChanged()
  }

  @objc private func beginUpload() {
    guard selectedProject != nil, serverURL != nil, !token.isEmpty else { return }
    uploadButton.isEnabled = false
    uploadButton.isHidden = true
    table.isHidden = true
    signOutButton.isHidden = true
    titleLabel.text = "Uploading…"
    detailLabel.text = "Preparing your feedback chunk"
    spinner.isHidden = false
    spinner.startAnimating()
    prepareProvider(at: 0)
  }

  private func prepareProvider(at index: Int) {
    guard index < providers.count else {
      do { try uploadChunk() } catch { showError("The files could not be prepared for upload.") }
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
        let copiedName = fileName
        DispatchQueue.main.async {
          guard !self.didClose else { try? FileManager.default.removeItem(at: copyURL); return }
          self.temporaryURLs.append(copyURL)
          do {
            let mime = UTType(identifier)?.preferredMIMEType ?? "application/octet-stream"
            if ["image/heic", "image/heif", "image/tiff"].contains(mime) {
              let jpeg = try self.jpegRepresentation(copyURL)
              self.temporaryURLs.append(jpeg)
              let jpegName = URL(fileURLWithPath: copiedName).deletingPathExtension().lastPathComponent + ".jpg"
              self.preparedFiles.append(SharedFile(url: jpeg, name: jpegName, mime: "image/jpeg"))
            } else {
              self.preparedFiles.append(SharedFile(url: copyURL, name: copiedName, mime: mime))
            }
            self.prepareProvider(at: providerIndex + 1)
          } catch { self.showError("One of the files could not be prepared. No chunk was uploaded.") }
        }
      } catch {
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
    request.httpBody = try JSONSerialization.data(withJSONObject: ["image_count": preparedFiles.count])
    request.timeoutInterval = 30
    startingDraft = true
    let session = secureSession(requestTimeout: 30, resourceTimeout: 30)
    session.dataTask(with: request) { [weak self] data, response, error in
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
    guard index < preparedFiles.count else { completeChunk(); return }
    detailLabel.text = "Uploading file \(index + 1) of \(preparedFiles.count)"
    let file = preparedFiles[index]
    let boundary = "Uploadiny-\(UUID().uuidString)"
    do {
      let bodyURL = try multipartBody(file: file, boundary: boundary)
      var request = authorizedRequest(base.appendingPathComponent("chunks").appendingPathComponent(draftID).appendingPathComponent("images"))
      request.httpMethod = "POST"
      request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
      let session = secureSession()
      session.uploadTask(with: request, fromFile: bodyURL) { [weak self] data, response, error in
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
          self.uploadFile(at: index + 1)
        }
      }.resume()
    } catch { showError("A file could not be prepared. The incomplete group was not published.") }
  }

  private func multipartBody(file: SharedFile, boundary: String) throws -> URL {
    let bodyURL = FileManager.default.temporaryDirectory.appendingPathComponent("uploadiny-\(UUID().uuidString).multipart")
    temporaryURLs.append(bodyURL)
    FileManager.default.createFile(atPath: bodyURL.path, contents: nil)
    let output = try FileHandle(forWritingTo: bodyURL)
    defer { try? output.close() }
    let safeName = file.name.replacingOccurrences(of: "\\", with: "_").replacingOccurrences(of: "\"", with: "_").replacingOccurrences(of: "\r", with: "_").replacingOccurrences(of: "\n", with: "_")
    let header = "--\(boundary)\r\nContent-Disposition: form-data; name=\"file\"; filename=\"\(safeName)\"\r\nContent-Type: \(file.mime)\r\n\r\n"
    try output.write(contentsOf: Data(header.utf8))
    guard let input = InputStream(url: file.url) else { throw UploadError.cannotReadFile }
    input.open()
    defer { input.close() }
    var buffer = [UInt8](repeating: 0, count: 64 * 1024)
    while input.hasBytesAvailable {
      let count = input.read(&buffer, maxLength: buffer.count)
      if count < 0 { throw UploadError.cannotReadFile }
      if count == 0 { break }
      try output.write(contentsOf: Data(buffer[0..<count]))
    }
    try output.write(contentsOf: Data("\r\n--\(boundary)--\r\n".utf8))
    return bodyURL
  }

  private func completeChunk() {
    guard let base = serverURL, let draftID else { return }
    var request = authorizedRequest(base.appendingPathComponent("chunks").appendingPathComponent(draftID).appendingPathComponent("complete"))
    request.httpMethod = "POST"
    let session = secureSession()
    session.dataTask(with: request) { [weak self] data, response, error in
      DispatchQueue.main.async {
        guard let self, !self.didClose else { return }
        if let response = response as? HTTPURLResponse, response.statusCode == 401 {
          self.requireSignIn("Your iPhone sign-in is no longer valid. Sign in again.")
          return
        }
        guard error == nil, let response = response as? HTTPURLResponse, response.statusCode == 200,
              let data, let result = try? JSONDecoder().decode(ChunkResult.self, from: data), result.images.count == self.preparedFiles.count else {
          self.showError("Check your project before trying again: the final upload response was unavailable.")
          return
        }
        self.draftID = nil
        let thumbnail = self.preparedFiles.first.flatMap { self.imagePreview($0.url) }
        self.cleanupFiles()
        self.spinner.stopAnimating()
        self.spinner.isHidden = true
        self.titleLabel.text = "Uploaded to \(self.selectedProject?.name ?? "project")"
        self.detailLabel.text = result.images.prefix(3).map { $0.name }.joined(separator: "\n") + (result.images.count > 3 ? "\n+ \(result.images.count - 3) more files" : "") + "\nTap anywhere to close"
        self.titleLabel.textAlignment = .center
        self.detailLabel.textAlignment = .center
        self.closeButton.setTitle("Done", for: .normal)
        self.closeButton.configuration?.image = UIImage(systemName: "checkmark", withConfiguration: UIImage.SymbolConfiguration(pointSize: 14, weight: .semibold))
        self.preview.image = thumbnail
        self.preview.isHidden = thumbnail == nil
        self.canDismiss = true
        self.preferredContentSize = CGSize(width: 0, height: thumbnail == nil ? 300 : 680)
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
    secureSession(requestTimeout: 10, resourceTimeout: 10).dataTask(with: request) { _, _, _ in
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

  private func imagePreview(_ url: URL) -> UIImage? {
    guard let source = CGImageSourceCreateWithURL(url as CFURL, nil) else { return nil }
    let options: [CFString: Any] = [kCGImageSourceCreateThumbnailFromImageAlways: true, kCGImageSourceCreateThumbnailWithTransform: true, kCGImageSourceThumbnailMaxPixelSize: 1280]
    guard let image = CGImageSourceCreateThumbnailAtIndex(source, 0, options as CFDictionary) else { return nil }
    return UIImage(cgImage: image)
  }

  private func showError(_ text: String) {
    DispatchQueue.main.async {
      self.cancelDraft()
      self.cleanupFiles()
      self.spinner.stopAnimating()
      self.spinner.isHidden = true
      self.table.isHidden = true
      self.uploadButton.isHidden = true
      self.titleLabel.text = "Upload could not finish"
      self.detailLabel.text = text
    }
  }

  private func cleanupFiles() {
    for url in temporaryURLs { try? FileManager.default.removeItem(at: url) }
    temporaryURLs.removeAll()
  }

  @objc private func closeExtension() {
    guard !didClose else { return }
    didClose = true
    closeButton.isEnabled = false
    detailLabel.text = "Closing…"
    if startingDraft { return }
    uploadSession?.invalidateAndCancel()
    cancelDraft { self.finishClosing() }
  }

  private func finishClosing() {
    uploadSession?.invalidateAndCancel()
    cleanupFiles()
    extensionContext?.completeRequest(returningItems: nil)
  }

  @objc private func dismissAfterSuccess() {
    guard canDismiss else { return }
    closeExtension()
  }
}

private struct ProjectList: Decodable { let projects: [Project] }
private struct Project: Decodable { let id: Int; let name: String; let slug: String; let description: String? }
private struct SharedFile { let url: URL; let name: String; let mime: String }
private struct ChunkResult: Decodable { let id: String; let images: [UploadedImage] }
private struct UploadedImage: Decodable { let id: String; let name: String }
private enum UploadError: Error { case cannotReadFile }

private struct DraftResult: Decodable { let id: String }

private struct DeviceTokenResult: Decodable { let token: String }

private enum UploadinyDeviceTokenStore {
  private static let service = "test.uploadiny.app.share"
  private static let account = "uploadiny-device-token"

  static func read() -> String? {
    var item: CFTypeRef?
    let query: [CFString: Any] = [
      kSecClass: kSecClassGenericPassword,
      kSecAttrService: service,
      kSecAttrAccount: account,
      kSecReturnData: true,
      kSecMatchLimit: kSecMatchLimitOne,
    ]
    guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
          let data = item as? Data,
          let token = String(data: data, encoding: .utf8),
          !token.isEmpty else {
      return nil
    }
    return token
  }

  static func write(_ token: String) throws {
    delete()
    let attributes: [CFString: Any] = [
      kSecClass: kSecClassGenericPassword,
      kSecAttrService: service,
      kSecAttrAccount: account,
      kSecAttrAccessible: kSecAttrAccessibleWhenUnlockedThisDeviceOnly,
      kSecValueData: Data(token.utf8),
    ]
    guard SecItemAdd(attributes as CFDictionary, nil) == errSecSuccess else {
      throw UploadinyDeviceTokenStoreError.unavailable
    }
  }

  static func delete() {
    let query: [CFString: Any] = [
      kSecClass: kSecClassGenericPassword,
      kSecAttrService: service,
      kSecAttrAccount: account,
    ]
    SecItemDelete(query as CFDictionary)
  }
}

private enum UploadinyDeviceTokenStoreError: Error { case unavailable }

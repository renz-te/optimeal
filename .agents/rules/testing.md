# OptiMeal Testing & Script Security Rules

- **Quarantine Directory**: All standalone PHP verification and test scripts must reside inside the `tests/` directory, never in the workspace root or public web folders.
- **CLI Guard**: Every PHP script in `tests/` must include a strict SAPI check as its first executable line:
  ```php
  if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
  ```
- **Web Access Denied**: The `tests/` directory must be guarded by a `.htaccess` file prohibiting direct HTTP requests.
- **Cookie & State Files**: Test scripts must write temporary session cookies and scratch state to the system temporary directory (`sys_get_temp_dir()`), never inside the repository.
- **Git Ignore**: Test outputs, cookie files, and `.txt` artifacts inside `tests/` must remain untracked in `.gitignore`.

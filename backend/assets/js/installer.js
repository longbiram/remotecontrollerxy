/**
 * Installer Wizard interactive logic
 * Handles multi-step navigation, live database inspection, existing database detection,
 * missing table selection, and existing admin preservation.
 */

document.addEventListener('DOMContentLoaded', function () {
  let currentStep = 1;
  const totalSteps = 3;

  const stepNodes = document.querySelectorAll('.step-node');
  const stepPanels = document.querySelectorAll('.step-panel');
  const btnNext = document.getElementById('btnNext');
  const btnPrev = document.getElementById('btnPrev');
  const btnSubmit = document.getElementById('btnSubmit');
  const btnTestDb = document.getElementById('btnTestDb');
  const dbInspectionResult = document.getElementById('dbInspectionResult');

  const installScopeInput = document.getElementById('install_scope');
  const dbAlreadyExistsInput = document.getElementById('db_already_exists');

  const step3DbSummary = document.getElementById('step3DbSummary');
  const existingAdminCard = document.getElementById('existingAdminCard');
  const keepExistingAdminCheckbox = document.getElementById('keep_existing_admin');
  const existingAdminDesc = document.getElementById('existingAdminDesc');
  const adminFieldsWrapper = document.getElementById('adminFieldsWrapper');
  const adminUserInput = document.getElementById('admin_user');
  const adminEmailInput = document.getElementById('admin_email');
  const adminPassInput = document.getElementById('admin_pass');
  const adminPassConfirmInput = document.getElementById('admin_pass_confirm');

  const seedDataCheckbox = document.getElementById('seed_data');
  const seedCatalogTitle = document.getElementById('seedCatalogTitle');
  const seedCatalogDesc = document.getElementById('seedCatalogDesc');

  let inspectionCache = null;
  let lastInspectedKey = '';

  function getDbKey() {
    const host = document.getElementById('db_host').value.trim();
    const port = document.getElementById('db_port').value.trim();
    const dbname = document.getElementById('db_name').value.trim();
    const user = document.getElementById('db_user').value.trim();
    const pass = document.getElementById('db_pass').value;
    return `${host}:${port}:${dbname}:${user}:${pass}`;
  }

  function updateSteps(step) {
    currentStep = step;
    stepNodes.forEach((node, idx) => {
      const stepIdx = idx + 1;
      node.classList.remove('active', 'done');
      if (stepIdx === currentStep) {
        node.classList.add('active');
      } else if (stepIdx < currentStep) {
        node.classList.add('done');
      }
    });

    stepPanels.forEach((panel) => {
      const pStep = parseInt(panel.getAttribute('data-step'), 10);
      panel.style.display = pStep === currentStep ? 'block' : 'none';
    });

    if (btnPrev) {
      btnPrev.style.display = currentStep > 1 ? 'inline-flex' : 'none';
    }

    if (btnNext && btnSubmit) {
      if (currentStep === totalSteps) {
        btnNext.style.display = 'none';
        btnSubmit.style.display = 'inline-flex';
      } else {
        btnNext.style.display = 'inline-flex';
        btnSubmit.style.display = 'none';
      }
    }
  }

  // Handle Next button click
  if (btnNext) {
    btnNext.addEventListener('click', async function () {
      if (currentStep === 1) {
        const hasFailures = document.querySelectorAll('.badge.fail').length > 0;
        if (hasFailures) {
          alert('Please resolve system environment requirements before proceeding.');
          return;
        }
        updateSteps(2);
        return;
      }

      if (currentStep === 2) {
        const host = document.getElementById('db_host').value.trim();
        const port = document.getElementById('db_port').value.trim();
        const dbname = document.getElementById('db_name').value.trim();
        const user = document.getElementById('db_user').value.trim();

        if (!host || !port || !dbname || !user) {
          alert('Please fill in all required database connection fields.');
          return;
        }

        const currentKey = getDbKey();
        // If not inspected yet or credentials changed, inspect automatically before advancing
        if (!inspectionCache || lastInspectedKey !== currentKey) {
          btnNext.disabled = true;
          const origNextText = btnNext.innerHTML;
          btnNext.innerHTML = 'Inspecting database...';

          const ok = await runDatabaseInspection();
          btnNext.disabled = false;
          btnNext.innerHTML = origNextText;

          if (!ok) {
            return; // Stay on step 2 if connection fails
          }
        }

        applyInspectionToStep3();
        updateSteps(3);
      }
    });
  }

  if (btnPrev) {
    btnPrev.addEventListener('click', function () {
      if (currentStep > 1) {
        updateSteps(currentStep - 1);
      }
    });
  }

  // Live Inspection logic
  async function runDatabaseInspection() {
    const host = document.getElementById('db_host').value.trim();
    const port = document.getElementById('db_port').value.trim();
    const dbname = document.getElementById('db_name').value.trim();
    const user = document.getElementById('db_user').value.trim();
    const pass = document.getElementById('db_pass').value;

    dbInspectionResult.style.display = 'block';
    dbInspectionResult.innerHTML = `
      <div class="alert alert-info" style="margin-top: 1rem;">
        <svg style="width: 18px; height: 18px; fill: currentColor; animation: spin 1s linear infinite;" viewBox="0 0 24 24"><path d="M12 4V2C6.48 2 2 6.48 2 12h2c0-4.41 3.59-8 8-8zm0 14c-4.41 0-8-3.59-8-8H2c0 5.52 4.48 10 10 10v-2zm8-6h2c0-5.52-4.48-10-10-10v2c4.41 0 8 3.59 8 8zm-2 0c0 3.31-2.69 6-6 6v2c4.41 0 8-3.59 8-8h-2z"/></svg>
        Connecting to MySQL server and inspecting '${escapeHtml(dbname)}'...
      </div>`;

    if (btnTestDb) {
      btnTestDb.disabled = true;
      btnTestDb.innerText = 'Inspecting...';
    }

    try {
      const formData = new FormData();
      formData.append('action', 'test_db');
      formData.append('host', host);
      formData.append('port', port);
      formData.append('name', dbname);
      formData.append('user', user);
      formData.append('pass', pass);

      const res = await fetch('install.php', {
        method: 'POST',
        body: formData,
      });

      const data = await res.json();
      if (!data.success) {
        dbInspectionResult.innerHTML = `
          <div class="alert alert-danger" style="margin-top: 1rem;">
            <strong>Connection Failed:</strong> ${escapeHtml(data.message)}
          </div>`;
        return false;
      }

      inspectionCache = data;
      lastInspectedKey = getDbKey();
      renderInspectionUI(data);
      return true;
    } catch (err) {
      dbInspectionResult.innerHTML = `
        <div class="alert alert-danger" style="margin-top: 1rem;">
          <strong>Error inspecting database:</strong> ${escapeHtml(err.message)}
        </div>`;
      return false;
    } finally {
      if (btnTestDb) {
        btnTestDb.disabled = false;
        btnTestDb.innerHTML = `
          <svg style="width: 15px; height: 15px; fill: currentColor;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9v-2h2v2zm0-4H9V7h2v5zm4 4h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
          Test Connection & Inspect DB`;
      }
    }
  }

  // Handle Manual DB Test Button Click
  if (btnTestDb) {
    btnTestDb.addEventListener('click', function () {
      const host = document.getElementById('db_host').value.trim();
      const port = document.getElementById('db_port').value.trim();
      const dbname = document.getElementById('db_name').value.trim();
      const user = document.getElementById('db_user').value.trim();

      if (!host || !port || !dbname || !user) {
        alert('Please fill in database host, port, database name, and username.');
        return;
      }
      runDatabaseInspection();
    });
  }

  // Render the inspection result and missing tables selector
  function renderInspectionUI(data) {
    const insp = data.inspection;
    const details = data.table_details || {};
    const dbName = insp.database;

    dbAlreadyExistsInput.value = insp.db_exists ? '1' : '0';

    if (!insp.db_exists) {
      // 1. Fresh Database to be created
      installScopeInput.value = 'full_fresh';
      dbInspectionResult.innerHTML = `
        <div class="inspection-card info-mode">
          <div class="inspection-header">
            <div class="inspection-title">
              <span style="color: #818cf8;">ℹ️</span> Database '${escapeHtml(dbName)}' Not Found
            </div>
            <span class="badge-tag blue">Fresh Install</span>
          </div>
          <p style="font-size: 0.86rem; color: #cbd5e1; line-height: 1.5; margin-bottom: 0.75rem;">
            The database does not exist yet. The wizard will create <code>${escapeHtml(dbName)}</code> and initialize all 8 required tables.
          </p>
          <div class="chip-list">
            ${insp.missing_tables.map(t => `<span class="table-chip missing">+ ${escapeHtml(t)}</span>`).join('')}
          </div>
        </div>`;
    } else if (insp.has_missing) {
      // 2. Database already exists, but SOME tables are MISSING
      installScopeInput.value = 'missing_only';
      const existingChips = insp.existing_tables.map(t => `<span class="table-chip present">✓ ${escapeHtml(t)}</span>`).join('');
      const missingChips = insp.missing_tables.map(t => `<span class="table-chip missing">⚠️ ${escapeHtml(t)}</span>`).join('');

      let missingCheckboxesHtml = insp.missing_tables.map(t => {
        const meta = details[t] || { title: t, category: 'Schema', description: '' };
        return `
          <label class="missing-table-item">
            <input type="checkbox" name="missing_tables[]" value="${escapeHtml(t)}" checked class="missing-table-cb">
            <div class="meta">
              <div class="tbl-name">
                ${escapeHtml(meta.title)} (<code>${escapeHtml(t)}</code>)
                <span class="tbl-cat">${escapeHtml(meta.category)}</span>
              </div>
              <div class="tbl-desc">${escapeHtml(meta.description)}</div>
            </div>
          </label>`;
      }).join('');

      dbInspectionResult.innerHTML = `
        <div class="inspection-card warning-mode">
          <div class="inspection-header">
            <div class="inspection-title">
              <span style="color: #fbbf24;">⚠️</span> Database '${escapeHtml(dbName)}' Already Exists
            </div>
            <span class="badge-tag amber">${insp.missing_tables.length} Missing Table(s)</span>
          </div>
          <p style="font-size: 0.86rem; color: #cbd5e1; line-height: 1.5;">
            The database <strong>${escapeHtml(dbName)}</strong> was found with <strong>${insp.existing_tables.length}</strong> existing table(s).
            To prevent data loss, the installer will <strong>not</strong> re-install existing tables.
          </p>

          <div style="margin-top: 0.85rem;">
            <div style="font-size: 0.78rem; font-weight: 700; color: #34d399; text-transform: uppercase; letter-spacing: 0.04em;">Existing Tables Preserved (Safe):</div>
            <div class="chip-list" style="margin-bottom: 0.75rem;">
              ${existingChips}
            </div>
          </div>

          <!-- Mode Selector -->
          <div class="action-mode-box">
            <label class="action-mode-option selected" id="optMissingOnly">
              <input type="radio" name="scope_choice" value="missing_only" checked>
              <div>
                <div class="title">Install only missing tables (Recommended)</div>
                <div class="desc">Preserves all existing data. Installs only the missing table(s) selected below.</div>
              </div>
            </label>
            <label class="action-mode-option" id="optSkipTables">
              <input type="radio" name="scope_choice" value="none">
              <div>
                <div class="title">Skip table installation</div>
                <div class="desc">Do not install any tables. Connect backend to existing database as-is.</div>
              </div>
            </label>
          </div>

          <!-- Missing Tables Checkbox Group -->
          <div class="missing-tables-container" id="missingTablesSelector">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
              <span style="font-size: 0.82rem; font-weight: 700; color: #fbbf24;">Missing Tables to Install:</span>
              <button type="button" id="btnSelectAllMissing" style="background: none; border: none; color: #a5b4fc; font-size: 0.75rem; cursor: pointer; text-decoration: underline;">Select All</button>
            </div>
            ${missingCheckboxesHtml}
          </div>
        </div>`;

      // Attach radio toggles
      const radioMissing = document.querySelector('input[name="scope_choice"][value="missing_only"]');
      const radioSkip = document.querySelector('input[name="scope_choice"][value="none"]');
      const optMissingOnly = document.getElementById('optMissingOnly');
      const optSkipTables = document.getElementById('optSkipTables');
      const missingSelector = document.getElementById('missingTablesSelector');

      if (radioMissing && radioSkip) {
        radioMissing.addEventListener('change', function () {
          if (this.checked) {
            installScopeInput.value = 'missing_only';
            optMissingOnly.classList.add('selected');
            optSkipTables.classList.remove('selected');
            missingSelector.style.display = 'block';
          }
        });

        radioSkip.addEventListener('change', function () {
          if (this.checked) {
            installScopeInput.value = 'none';
            optSkipTables.classList.add('selected');
            optMissingOnly.classList.remove('selected');
            missingSelector.style.display = 'none';
          }
        });
      }

      const btnSelectAll = document.getElementById('btnSelectAllMissing');
      if (btnSelectAll) {
        btnSelectAll.addEventListener('click', function () {
          document.querySelectorAll('.missing-table-cb').forEach(cb => cb.checked = true);
        });
      }
    } else {
      // 3. Database already exists and ALL tables are PRESENT!
      installScopeInput.value = 'none';
      const existingChips = insp.existing_tables.map(t => `<span class="table-chip present">✓ ${escapeHtml(t)}</span>`).join('');

      dbInspectionResult.innerHTML = `
        <div class="inspection-card success-mode">
          <div class="inspection-header">
            <div class="inspection-title">
              <span style="color: #34d399;">✓</span> Database '${escapeHtml(dbName)}' Already Exists
            </div>
            <span class="badge-tag green">All ${insp.existing_tables.length} Tables Present</span>
          </div>
          <p style="font-size: 0.86rem; color: #cbd5e1; line-height: 1.5; margin-bottom: 0.75rem;">
            Database already exists and contains all required tables!
            <strong>No table installation is needed.</strong> All existing tables and data will be preserved.
            Click <strong>Next</strong> to configure your backend connection.
          </p>
          <div class="chip-list">
            ${existingChips}
          </div>
        </div>`;
    }
  }

  // Update Step 3 inputs based on the database inspection
  function applyInspectionToStep3() {
    if (!inspectionCache || !inspectionCache.inspection) return;
    const insp = inspectionCache.inspection;

    if (insp.db_exists) {
      step3DbSummary.style.display = 'block';
      step3DbSummary.innerHTML = `
        <div class="alert alert-info" style="font-size: 0.86rem; margin-bottom: 1.25rem;">
          <strong>Database:</strong> Connected to existing database <code>${escapeHtml(insp.database)}</code> (${insp.existing_tables.length} existing tables).
        </div>`;

      // Handle Admin Account
      if (insp.admin_count > 0) {
        existingAdminCard.style.display = 'block';
        existingAdminDesc.innerText = `Found ${insp.admin_count} existing admin user(s) in database '${insp.database}'. Keep this checked to preserve your current admin login credentials.`;
        keepExistingAdminCheckbox.checked = true;
        setAdminFieldsRequired(false);
      } else {
        existingAdminCard.style.display = 'none';
        keepExistingAdminCheckbox.checked = false;
        setAdminFieldsRequired(true);
      }

      // Handle Catalog Seeding
      if (insp.content_count > 0) {
        seedDataCheckbox.checked = false;
        seedCatalogTitle.innerText = 'Import Starter Catalog (Optional)';
        seedCatalogDesc.innerHTML = `<span style="color: #fbbf24;">Found ${insp.content_count} content items in database.</span> Unchecked by default to avoid duplicating or overwriting your existing catalog.`;
      } else {
        seedDataCheckbox.checked = true;
        seedCatalogTitle.innerText = 'Import PikaShow Catalog (Recommended for New DB)';
        seedCatalogDesc.innerText = 'Pre-populates database with Bollywood, Hollywood, Web Series, and Live TV channels.';
      }
    } else {
      step3DbSummary.style.display = 'none';
      existingAdminCard.style.display = 'none';
      keepExistingAdminCheckbox.checked = false;
      setAdminFieldsRequired(true);
      seedDataCheckbox.checked = true;
      seedCatalogTitle.innerText = 'Import PikaShow Catalog (Recommended for New DB)';
      seedCatalogDesc.innerText = 'Pre-populates database with Bollywood, Hollywood, Web Series, and Live TV channels.';
    }
  }

  function setAdminFieldsRequired(isRequired) {
    if (isRequired) {
      adminFieldsWrapper.style.opacity = '1';
      adminFieldsWrapper.style.pointerEvents = 'auto';
      adminUserInput.required = true;
      adminEmailInput.required = true;
      adminPassInput.required = true;
      adminPassConfirmInput.required = true;
    } else {
      adminFieldsWrapper.style.opacity = '0.45';
      adminFieldsWrapper.style.pointerEvents = 'none';
      adminUserInput.required = false;
      adminEmailInput.required = false;
      adminPassInput.required = false;
      adminPassConfirmInput.required = false;
    }
  }

  if (keepExistingAdminCheckbox) {
    keepExistingAdminCheckbox.addEventListener('change', function () {
      setAdminFieldsRequired(!this.checked);
    });
  }

  // -------------------------------------------------------------
  // Setup Mode Switching (Standard vs Restore from Backup)
  // -------------------------------------------------------------
  const tabBtnStandard = document.getElementById('tabBtnStandard');
  const tabBtnBackup = document.getElementById('tabBtnBackup');
  const stepperNav = document.getElementById('stepperNav');
  const backupRestorePanel = document.getElementById('backupRestorePanel');
  const standardBtnGroup = document.getElementById('standardBtnGroup');
  const installerActionInput = document.getElementById('installerActionInput');

  window.switchSetupMode = function (mode) {
    if (mode === 'backup') {
      if (tabBtnStandard) tabBtnStandard.classList.remove('active');
      if (tabBtnBackup) tabBtnBackup.classList.add('active');
      if (stepperNav) stepperNav.style.display = 'none';
      if (backupRestorePanel) backupRestorePanel.style.display = 'block';
      if (standardBtnGroup) standardBtnGroup.style.display = 'none';
      stepPanels.forEach(p => p.style.display = 'none');
      if (installerActionInput) installerActionInput.value = 'install_from_backup';
    } else {
      if (tabBtnStandard) tabBtnStandard.classList.add('active');
      if (tabBtnBackup) tabBtnBackup.classList.remove('active');
      if (stepperNav) stepperNav.style.display = 'flex';
      if (backupRestorePanel) backupRestorePanel.style.display = 'none';
      if (standardBtnGroup) standardBtnGroup.style.display = 'flex';
      updateSteps(currentStep);
      if (installerActionInput) installerActionInput.value = 'install';
    }
  };

  // -------------------------------------------------------------
  // Backup File Selection & Dynamic Live Inspection
  // -------------------------------------------------------------
  const installerDropzone = document.getElementById('installerDropzone');
  const installerBackupFile = document.getElementById('installerBackupFile');
  const installerDropzoneTitle = document.getElementById('installerDropzoneTitle');
  const installerDropzoneHint = document.getElementById('installerDropzoneHint');
  const installerBackupStatsCard = document.getElementById('installerBackupStatsCard');

  if (installerDropzone && installerBackupFile) {
    installerDropzone.addEventListener('click', () => installerBackupFile.click());

    installerDropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      installerDropzone.classList.add('dragover');
    });

    installerDropzone.addEventListener('dragleave', () => {
      installerDropzone.classList.remove('dragover');
    });

    installerDropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      installerDropzone.classList.remove('dragover');
      if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        installerBackupFile.files = e.dataTransfer.files;
        handleBackupFilePicked(e.dataTransfer.files[0]);
      }
    });

    installerBackupFile.addEventListener('change', () => {
      if (installerBackupFile.files && installerBackupFile.files[0]) {
        handleBackupFilePicked(installerBackupFile.files[0]);
      }
    });
  }

  async function handleBackupFilePicked(file) {
    if (installerDropzoneTitle) installerDropzoneTitle.innerText = file.name;
    if (installerDropzoneHint) installerDropzoneHint.innerText = 'Size: ' + formatBytesJS(file.size) + ' — Inspecting archive...';

    if (installerBackupStatsCard) {
      installerBackupStatsCard.style.display = 'block';
      installerBackupStatsCard.innerHTML = `
        <div class="alert alert-info" style="font-size: 0.85rem; margin-top: 0.75rem;">
          <svg style="width: 16px; height: 16px; fill: currentColor; animation: spin 1s linear infinite; margin-right: 6px;" viewBox="0 0 24 24"><path d="M12 4V2C6.48 2 2 6.48 2 12h2c0-4.41 3.59-8 8-8zm0 14c-4.41 0-8-3.59-8-8H2c0 5.52 4.48 10 10 10v-2zm8-6h2c0-5.52-4.48-10-10-10v2c4.41 0 8 3.59 8 8zm-2 0c0 3.31-2.69 6-6 6v2c4.41 0 8-3.59 8-8h-2z"/></svg>
          Inspecting backup archive contents...
        </div>`;
    }

    try {
      const fd = new FormData();
      fd.append('action', 'inspect_backup_file');
      fd.append('backup_file', file);

      const resp = await fetch('install.php', { method: 'POST', body: fd });
      const res = await resp.json();

      if (!res.success) {
        if (installerBackupStatsCard) {
          installerBackupStatsCard.innerHTML = `
            <div class="alert alert-danger" style="font-size: 0.85rem; margin-top: 0.75rem;">
              <strong>Inspection Notice:</strong> ${escapeHtml(res.message)}
            </div>`;
        }
        return;
      }

      const d = res.details;
      const isZip = d.type === 'full_system';
      const badgeClass = isZip ? 'badge-tag amber' : 'badge-tag blue';
      const badgeText = isZip ? 'Full System Archive (.zip)' : 'Database SQL Dump (.sql)';

      let uploadsHtml = '';
      if (d.has_uploads) {
        uploadsHtml = `<div style="font-size: 0.82rem; color: #38bdf8; margin-top: 4px;">✓ Includes <strong>${d.uploads_count}</strong> media assets to unpack into <code>/uploads</code></div>`;
      } else {
        uploadsHtml = `<div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 4px;">Database schema & records dump</div>`;
      }

      if (installerBackupStatsCard) {
        installerBackupStatsCard.innerHTML = `
          <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.35); border-radius: 12px; padding: 1rem 1.25rem; margin-top: 0.75rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 6px;">
              <div style="display: flex; align-items: center; gap: 8px;">
                <svg style="width: 20px; height: 20px; fill: #10b981;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                <strong style="color: #fff; font-size: 0.95rem;">Valid Backup Archive Verified</strong>
              </div>
              <span class="${badgeClass}">${badgeText}</span>
            </div>
            <div style="font-size: 0.85rem; color: #cbd5e1; line-height: 1.5;">
              Archive: <code>${escapeHtml(file.name)}</code> (${d.size_formatted})
            </div>
            ${d.tables_count ? `<div style="font-size: 0.82rem; color: #6ee7b7; margin-top: 3px;">✓ <strong>${d.tables_count}</strong> database tables &bull; <strong>${d.records_count || 0}</strong> records found</div>` : ''}
            ${uploadsHtml}
          </div>`;
      }
    } catch (err) {
      if (installerBackupStatsCard) {
        installerBackupStatsCard.innerHTML = `
          <div class="alert alert-info" style="font-size: 0.85rem; margin-top: 0.75rem;">
            <strong>File Selected:</strong> ${escapeHtml(file.name)} (${formatBytesJS(file.size)}). Ready for restore upon installation.
          </div>`;
      }
    }
  }

  // -------------------------------------------------------------
  // Test MySQL Connection in Backup Mode
  // -------------------------------------------------------------
  const btnTestBackupDb = document.getElementById('btnTestBackupDb');
  const backupDbTestResult = document.getElementById('backupDbTestResult');

  if (btnTestBackupDb && backupDbTestResult) {
    btnTestBackupDb.addEventListener('click', async function () {
      const host = document.getElementById('b_db_host').value.trim();
      const port = document.getElementById('b_db_port').value.trim();
      const dbname = document.getElementById('b_db_name').value.trim();
      const user = document.getElementById('b_db_user').value.trim();
      const pass = document.getElementById('b_db_pass').value;

      backupDbTestResult.style.display = 'block';
      backupDbTestResult.innerHTML = `
        <div class="alert alert-info" style="font-size: 0.85rem;">
          Testing connection to MySQL server...
        </div>`;
      btnTestBackupDb.disabled = true;

      try {
        const fd = new FormData();
        fd.append('action', 'test_db');
        fd.append('host', host);
        fd.append('port', port);
        fd.append('name', dbname);
        fd.append('user', user);
        fd.append('pass', pass);

        const resp = await fetch('install.php', { method: 'POST', body: fd });
        const res = await resp.json();

        if (res.success) {
          backupDbTestResult.innerHTML = `
            <div class="alert alert-success" style="font-size: 0.85rem;">
              <strong>✓ Success:</strong> Connection established. Target database is ready for restore.
            </div>`;
        } else {
          backupDbTestResult.innerHTML = `
            <div class="alert alert-danger" style="font-size: 0.85rem;">
              <strong>Connection Error:</strong> ${escapeHtml(res.message)}
            </div>`;
        }
      } catch (err) {
        backupDbTestResult.innerHTML = `
          <div class="alert alert-danger" style="font-size: 0.85rem;">
            <strong>Error:</strong> ${escapeHtml(err.message)}
          </div>`;
      } finally {
        btnTestBackupDb.disabled = false;
      }
    });
  }

  // -------------------------------------------------------------
  // Toggle Admin Credentials Override in Backup Mode
  // -------------------------------------------------------------
  const keepBackupAdminCb = document.getElementById('keep_backup_admin');
  const backupAdminOverrideFields = document.getElementById('backupAdminOverrideFields');

  if (keepBackupAdminCb && backupAdminOverrideFields) {
    keepBackupAdminCb.addEventListener('change', function () {
      backupAdminOverrideFields.style.display = this.checked ? 'none' : 'block';
      const userInp = document.getElementById('b_admin_user');
      if (!this.checked && userInp) userInp.focus();
    });
  }

  function formatBytesJS(bytes) {
    if (!bytes || bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
  }

  // Final Form Submit Validation
  const form = document.getElementById('installerForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      const isBackupMode = installerActionInput && installerActionInput.value === 'install_from_backup';

      if (isBackupMode) {
        if (!installerBackupFile || !installerBackupFile.files || installerBackupFile.files.length === 0) {
          e.preventDefault();
          alert('Please select or drop a backup file (.zip or .sql) to restore.');
          return;
        }

        const isKeepingBackupAdmin = keepBackupAdminCb && keepBackupAdminCb.checked;
        if (!isKeepingBackupAdmin) {
          const pass = document.getElementById('b_admin_pass').value;
          const passConfirm = document.getElementById('b_admin_pass_confirm').value;

          if (!pass || pass.length < 6) {
            e.preventDefault();
            alert('Admin password must be at least 6 characters long.');
            return;
          }

          if (pass !== passConfirm) {
            e.preventDefault();
            alert('Admin passwords do not match.');
            return;
          }
        }

        const btnRestore = document.getElementById('btnSubmitBackupRestore');
        if (btnRestore) {
          btnRestore.disabled = true;
          btnRestore.innerHTML = 'Restoring Server from Backup... Please wait';
        }
        return;
      }

      // Standard mode validation
      const isKeepingAdmin = keepExistingAdminCheckbox && keepExistingAdminCheckbox.checked;

      if (!isKeepingAdmin) {
        const pass = adminPassInput.value;
        const passConfirm = adminPassConfirmInput.value;

        if (!pass || pass.length < 6) {
          e.preventDefault();
          alert('Admin password must be at least 6 characters long.');
          return;
        }

        if (pass !== passConfirm) {
          e.preventDefault();
          alert('Admin passwords do not match.');
          return;
        }
      }

      if (btnSubmit) {
        btnSubmit.disabled = true;
        btnSubmit.innerText = 'Installing PikaShow... Please wait';
      }
    });
  }

  function escapeHtml(str) {
    if (typeof str !== 'string') return str;
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Auto-activate backup mode if requested via URL
  if (window.location.search.includes('mode=restore_backup') || window.location.search.includes('restore=1')) {
    switchSetupMode('backup');
  } else {
    // Initialize standard stepper
    updateSteps(1);
  }
});

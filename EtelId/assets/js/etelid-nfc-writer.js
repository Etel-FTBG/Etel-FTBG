(function () {
  'use strict';

  var searchInput = document.getElementById('etelid-nfc-search');
  var resultsBox = document.getElementById('etelid-nfc-results');
  var selectedBox = document.getElementById('etelid-nfc-selected');
  var selectedName = document.getElementById('etelid-nfc-selected-name');
  var selectedIdLabel = document.getElementById('etelid-nfc-selected-id');
  var memberIdField = document.getElementById('etelid-nfc-member-id');
  var issueBtn = document.getElementById('etelid-nfc-issue-btn');
  var issuedBox = document.getElementById('etelid-nfc-issued');
  var urlField = document.getElementById('etelid-nfc-url');
  var copyBtn = document.getElementById('etelid-nfc-copy-btn');
  var testLink = document.getElementById('etelid-nfc-test-link');
  var writeBtn = document.getElementById('etelid-nfc-write-btn');
  var writeStatus = document.getElementById('etelid-nfc-write-status');

  var searchTimer = null;

  function post(action, data) {
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('nonce', EtelidNfcWriter.nonce);
    Object.keys(data || {}).forEach(function (key) {
      body.append(key, data[key]);
    });

    return fetch(EtelidNfcWriter.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (res) {
      return res.json();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      var q = searchInput.value.trim();
      clearTimeout(searchTimer);

      if (q.length < 2) {
        resultsBox.innerHTML = '';
        return;
      }

      searchTimer = setTimeout(function () {
        post('etelid_nfc_lookup_member', { q: q }).then(function (res) {
          if (!res.success) {
            resultsBox.innerHTML = '';
            return;
          }
          renderResults(res.data);
        });
      }, 300);
    });
  }

  function renderResults(rows) {
    resultsBox.innerHTML = '';

    if (!rows || rows.length === 0) {
      resultsBox.innerHTML = '<div class="etelid-nfc-result-item">No matches</div>';
      return;
    }

    rows.forEach(function (row) {
      var item = document.createElement('div');
      item.className = 'etelid-nfc-result-item';
      item.textContent = row.full_name + '  -  ' + row.etelid_number;
      item.addEventListener('click', function () {
        selectMember(row);
      });
      resultsBox.appendChild(item);
    });
  }

  function selectMember(row) {
    memberIdField.value = row.id;
    selectedName.textContent = row.full_name;
    selectedIdLabel.textContent = row.etelid_number;
    selectedBox.style.display = 'block';
    issuedBox.style.display = 'none';
    resultsBox.innerHTML = '';
    searchInput.value = '';
  }

  if (issueBtn) {
    issueBtn.addEventListener('click', function () {
      var memberId = memberIdField.value;
      if (!memberId) {
        return;
      }

      issueBtn.disabled = true;
      issueBtn.textContent = 'Generating...';

      post('etelid_nfc_issue', { member_id: memberId }).then(function (res) {
        issueBtn.disabled = false;
        issueBtn.textContent = 'Generate Profile Link';

        if (!res.success) {
          writeStatus.textContent = 'Error: ' + (res.data && res.data.message ? res.data.message : 'Could not issue card.');
          return;
        }

        urlField.value = res.data.url;
        testLink.href = res.data.url;
        issuedBox.style.display = 'block';
        writeStatus.textContent = '';
      });
    });
  }

  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      urlField.select();
      document.execCommand('copy');
      copyBtn.textContent = 'Copied';
      setTimeout(function () {
        copyBtn.textContent = 'Copy';
      }, 1500);
    });
  }

  if (writeBtn) {
    writeBtn.addEventListener('click', function () {
      writeToTag(urlField.value);
    });
  }

  function writeToTag(url) {
    if (typeof NDEFReader === 'undefined') {
      writeStatus.textContent = 'Web NFC is not available in this browser. Use Chrome on Android, ' +
        'over HTTPS, or write the tag with an app like "NFC Tools" using the URL above.';
      return;
    }

    writeStatus.textContent = 'Hold a blank NFC tag against the back of your phone...';

    var writer = new NDEFReader();

    writer.write({ records: [{ recordType: 'url', data: url }] })
      .then(function () {
        writeStatus.textContent = 'Tag written successfully.';
      })
      .catch(function (err) {
        writeStatus.textContent = 'Write failed: ' + err.message + '. Make sure NFC is on and a writable tag is touching the phone.';
      });
  }
})();

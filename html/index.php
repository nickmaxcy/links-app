<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/theme.php';
require __DIR__ . '/includes/layout.php';
require_login();

$pdo = get_pdo();

$platforms = get_platforms($pdo);
$platformsById = [];
foreach ($platforms as $p) {
    $platformsById[(int)$p['id']] = [
        'name' => $p['display_name'],
        'logo' => $p['logo_path'] ? '/assets/' . $p['logo_path'] : null,
        'fields' => $p['field_schema'],
    ];
}

$rows = $pdo->query('SELECT entity, school, device_name, platform_id, fields FROM devices')->fetchAll();
// Entity Access restrictions (set on the Users page) gate write actions -
// adding, editing, deleting - not viewing. Lookup always shows every
// device to every logged-in account regardless of those restrictions.
usort($rows, fn($a, $b) => strnatcasecmp($a['entity'], $b['entity'])
    ?: strnatcasecmp($a['school'], $b['school'])
    ?: strnatcasecmp($a['device_name'], $b['device_name']));

// Viewers never receive the value of a field marked "hide from viewer" on
// its platform — filtered here, server-side, before anything is embedded
// in the page, so it's not just hidden in the browser (which a viewer
// could bypass by reading the page's own data).
$currentRole = current_role();
$tree = [];
foreach ($rows as $row) {
    $fields = json_decode($row['fields'], true) ?: [];
    if ($currentRole === 'viewer') {
        $schema = $platformsById[(int)$row['platform_id']]['fields'] ?? [];
        foreach ($schema as $f) {
            if (!empty($f['hide_from_viewer'])) {
                unset($fields[$f['key']]);
            }
        }
    }
    $tree[$row['entity']][$row['school']][$row['device_name']] = [
        'platform_id' => (int)$row['platform_id'],
        'fields' => $fields,
    ];
}

$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
render_layout_head($pdo, 'Links Directory');
?>

<script>
var dlsiteObject = <?= json_encode($tree, $jsonFlags) ?>;
var platformMeta = <?= json_encode($platformsById, $jsonFlags) ?>;

window.onload = function() {
  var entitySel = document.getElementById("entity");
  var schoolSel = document.getElementById("school");
  var codecSel = document.getElementById("codec");
  var resultsCard = document.getElementById("resultsCard");

  for (var entity in dlsiteObject) {
    var option = document.createElement("option");
    option.value = entity;
    option.text = entity;
    entitySel.appendChild(option);
  }

  entitySel.onchange = function() {
    schoolSel.innerHTML = '<option value="" selected="selected">Please select entity first</option>';
    codecSel.innerHTML = '<option value="" selected="selected">Please select school first</option>';
    resultsCard.innerHTML = "";

    var selectedEntity = this.value;
    if (selectedEntity) {
      var schools = dlsiteObject[selectedEntity];
      for (var school in schools) {
        var option = document.createElement("option");
        option.value = school;
        option.text = school;
        schoolSel.appendChild(option);
      }
    }
  }

  schoolSel.onchange = function() {
    codecSel.innerHTML = '<option value="" selected="selected">Please select school first</option>';
    resultsCard.innerHTML = "";

    var selectedEntity = entitySel.value;
    var selectedSchool = this.value;
    if (selectedSchool) {
      var codecs = dlsiteObject[selectedEntity][selectedSchool];
      for (var codec in codecs) {
        var option = document.createElement("option");
        option.value = codec;
        option.text = codec;
        codecSel.appendChild(option);
      }
    }
  }

  codecSel.onchange = function() {
    var selectedEntity = entitySel.value;
    var selectedSchool = schoolSel.value;
    var selectedCodec = this.value;

    resultsCard.innerHTML = "";
    if (!selectedCodec) return;

    var details = dlsiteObject[selectedEntity][selectedSchool][selectedCodec];
    var meta = platformMeta[details.platform_id];
    if (!meta) return;

    var badge = document.createElement("div");
    badge.className = "header-badge";
    if (meta.logo) {
      var img = document.createElement("img");
      img.src = meta.logo;
      img.alt = meta.name;
      img.style.cssText = "height:24px;width:auto;vertical-align:middle;margin-right:8px;background:white;padding:2px 6px;border-radius:4px;border:1px solid var(--border-color);";
      badge.appendChild(img);
    }
    var b = document.createElement("b");
    b.textContent = meta.name;
    badge.appendChild(b);
    resultsCard.appendChild(badge);

    meta.fields.forEach(function(f) {
      var val = details.fields[f.key];
      if (val === undefined || val === null || val === "") return;

      var line = document.createElement("div");
      if (f.type === "url") {
        var url = val;
        if (!/^https?:\/\//i.test(url)) url = "http://" + url;
        line.textContent = f.label + ": ";
        var a = document.createElement("a");
        a.href = url;
        a.target = "_blank";
        a.rel = "noopener noreferrer";
        a.textContent = val;
        line.appendChild(a);
      } else {
        line.textContent = f.label + ": " + val;
      }
      resultsCard.appendChild(line);
    });
  }
}
</script>
<?php render_layout_body_open($pdo, 'Lookup', 'lookup'); ?>

  <div class="container">
    <div class="header">
      <img src="/<?= h(get_site_logo_path($pdo)) ?>" alt="Site Logo"/>
    </div>

    <form name="form1" id="form1">
      <div class="form-group">
        <label for="entity">Entity</label>
        <select name="entity" id="entity">
          <option value="" selected="selected">Select Entity</option>
        </select>
      </div>

      <div class="form-group">
        <label for="school">School</label>
        <select name="school" id="school">
          <option value="" selected="selected">Please select entity first</option>
        </select>
      </div>

      <div class="form-group">
        <label for="codec">Device</label>
        <select name="codec" id="codec">
          <option value="" selected="selected">Please select school first</option>
        </select>
      </div>

      <div class="results-card" id="resultsCard"></div>
    </form>
  </div>

<?php render_layout_close(); ?>

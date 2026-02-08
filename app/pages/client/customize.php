<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_role(['client']);

$user = current_user();
$maxLayers = 10;
$maxFileSize = 5 * 1024 * 1024;
$itemTypes = ['tshirt', 'cap', 'bag', 'logo'];
$itemTypeLabels = [
    'tshirt' => 'T-Shirt',
    'cap' => 'Cap',
    'bag' => 'Bag',
    'logo' => 'Logo Embroidery',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_image') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['ok' => false, 'error' => 'Invalid security token.']);
        exit;
    }

    $file = $_FILES['image'] ?? null;
    if (!$file || !isset($file['error']) || is_array($file['error'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid image upload.']);
        exit;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Image upload failed.']);
        exit;
    }

    if ($file['size'] > $maxFileSize) {
        echo json_encode(['ok' => false, 'error' => 'Image exceeds the 5MB limit.']);
        exit;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    $mimeType = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mimeType])) {
        echo json_encode(['ok' => false, 'error' => 'Only JPG or PNG images are allowed.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../../../public/uploads/designs/layers';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mimeType];
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode(['ok' => false, 'error' => 'Unable to save the image.']);
        exit;
    }

    echo json_encode(['ok' => true, 'path' => '/uploads/designs/layers/' . $filename]);
    exit;
}

$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_design') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $name = trim($_POST['design_name'] ?? '');
        $itemType = $_POST['item_type'] ?? '';
        $layersJson = $_POST['layers'] ?? '';
        $previewData = $_POST['preview_data'] ?? '';

        if ($name === '') {
            $errors[] = 'Design name is required.';
        }

        if (!in_array($itemType, $itemTypes, true)) {
            $errors[] = 'Invalid item type selected.';
        }

        $layers = json_decode($layersJson, true);
        if (!is_array($layers)) {
            $errors[] = 'Design layers could not be read.';
            $layers = [];
        }

        if (count($layers) === 0) {
            $errors[] = 'Add at least one layer before saving.';
        }

        if (count($layers) > $maxLayers) {
            $errors[] = 'Too many layers. Maximum is ' . $maxLayers . '.';
        }

        $previewPath = null;
        if ($previewData !== '') {
            if (!str_starts_with($previewData, 'data:image/png;base64,')) {
                $errors[] = 'Preview image format is invalid.';
            } else {
                $encoded = substr($previewData, strlen('data:image/png;base64,'));
                $decoded = base64_decode($encoded, true);
                if ($decoded === false) {
                    $errors[] = 'Preview image could not be decoded.';
                } else {
                    $previewDir = __DIR__ . '/../../../public/uploads/designs/previews';
                    if (!is_dir($previewDir)) {
                        mkdir($previewDir, 0775, true);
                    }
                    $previewFilename = bin2hex(random_bytes(16)) . '.png';
                    $previewDestination = $previewDir . '/' . $previewFilename;
                    if (file_put_contents($previewDestination, $decoded) === false) {
                        $errors[] = 'Unable to save preview image.';
                    } else {
                        $previewPath = '/uploads/designs/previews/' . $previewFilename;
                    }
                }
            }
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO custom_designs (owner_user_id, shop_id, name, item_type, preview_path, created_at)
                 VALUES (:owner_user_id, :shop_id, :name, :item_type, :preview_path, :created_at)'
            );
            $stmt->execute([
                'owner_user_id' => $user['id'],
                'shop_id' => null,
                'name' => $name,
                'item_type' => $itemType,
                'preview_path' => $previewPath,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $designId = (int) db()->lastInsertId();

            $layerStmt = db()->prepare(
                'INSERT INTO custom_design_layers
                    (design_id, layer_type, content, x, y, scale, rotation, color, font, font_size, font_weight, created_at)
                 VALUES
                    (:design_id, :layer_type, :content, :x, :y, :scale, :rotation, :color, :font, :font_size, :font_weight, :created_at)'
            );

            foreach ($layers as $layer) {
                $layerType = $layer['type'] ?? '';
                if (!in_array($layerType, ['image', 'text'], true)) {
                    continue;
                }
                $content = trim((string) ($layer['content'] ?? ''));
                if ($content === '') {
                    continue;
                }
                $layerStmt->execute([
                    'design_id' => $designId,
                    'layer_type' => $layerType,
                    'content' => $content,
                    'x' => (float) ($layer['x'] ?? 0),
                    'y' => (float) ($layer['y'] ?? 0),
                    'scale' => (float) ($layer['scale'] ?? 1),
                    'rotation' => (float) ($layer['rotation'] ?? 0),
                    'color' => $layerType === 'text' ? ($layer['color'] ?? null) : null,
                    'font' => $layerType === 'text' ? ($layer['font'] ?? null) : null,
                    'font_size' => $layerType === 'text' ? (int) ($layer['font_size'] ?? 24) : null,
                    'font_weight' => $layerType === 'text' ? (int) ($layer['font_weight'] ?? 600) : null,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }

            header('Location: /client/designs/' . $designId);
            exit;
        }
    }
}

$itemType = $_GET['type'] ?? 'tshirt';
if (!in_array($itemType, $itemTypes, true)) {
    $itemType = 'tshirt';
}

$pageTitle = 'Design Builder';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Design Builder</h1>
<p class="text-muted small">Create a custom <?= htmlspecialchars($itemTypeLabels[$itemType] ?? $itemType, ENT_QUOTES, 'UTF-8') ?> design by adding images or text layers.</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="d-flex flex-column gap-3">
    <form id="design-form" method="post" class="d-flex flex-column gap-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_design">
        <input type="hidden" name="layers" id="layers-field" value="[]">
        <input type="hidden" name="preview_data" id="preview-field" value="">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="design-name">Design name</label>
                <input type="text" class="form-control" id="design-name" name="design_name" placeholder="Summer merch" required>
            </div>
            <div class="col-12">
                <label class="form-label" for="item-type">Item type</label>
                <select class="form-select" id="item-type" name="item_type">
                    <?php foreach ($itemTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= $type === $itemType ? 'selected' : '' ?>>
                            <?= htmlspecialchars($itemTypeLabels[$type] ?? strtoupper($type), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="border rounded p-3 bg-white">
            <div class="d-flex flex-column gap-2">
                <div class="d-flex flex-wrap gap-2">
                    <input type="file" id="image-upload" accept="image/png,image/jpeg" class="form-control" style="max-width: 260px;">
                    <button type="button" class="btn btn-outline-primary" id="add-text">Add text layer</button>
                </div>
                <div class="d-flex flex-wrap gap-3">
                    <div class="flex-grow-1">
                        <div class="design-board" id="design-board"></div>
                        <p class="text-muted small mt-2 mb-0">Drag layers freely to reposition. Select a layer to edit its rotation, scale, and styling.</p>
                    </div>
                    <div class="design-panel">
                        <h2 class="h6">Layer controls</h2>
                        <div class="mb-2">
                            <label class="form-label">Scale</label>
                            <input type="range" class="form-range" min="0.5" max="2" step="0.05" id="scale-control">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Rotation</label>
                            <input type="range" class="form-range" min="-180" max="180" step="1" id="rotation-control">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Text color</label>
                            <input type="color" class="form-control form-control-color" id="color-control" value="#111111">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Font</label>
                            <select class="form-select" id="font-control">
                                <option value="Arial">Arial</option>
                                <option value="'Times New Roman'">Times New Roman</option>
                                <option value="'Courier New'">Courier New</option>
                                <option value="Georgia">Georgia</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Font size</label>
                            <input type="number" class="form-control" id="font-size-control" min="12" max="96" value="32">
                        </div>
                        <div class="mt-2">
                            <label class="form-label">Text thickness</label>
                            <input type="range" class="form-range" min="100" max="900" step="100" id="weight-control" value="600">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center">
            <span class="text-muted small">Max layers: <?= $maxLayers ?>. Max image size: 5MB.</span>
            <button type="submit" class="btn btn-primary">Save design</button>
        </div>
    </form>
</div>

<style>
.design-board {
    width: 320px;
    height: 320px;
    border: 2px dashed #ced4da;
    border-radius: 12px;
    background: #f8f9fa;
    position: relative;
    overflow: hidden;
}
.design-layer {
    position: absolute;
    cursor: move;
    user-select: none;
    transform-origin: center;
}
.design-layer.selected {
    outline: 2px solid #0d6efd;
    outline-offset: 2px;
}
.design-panel {
    min-width: 220px;
}
</style>

<script>
const maxLayers = <?= (int) $maxLayers ?>;
const board = document.getElementById('design-board');
const imageUpload = document.getElementById('image-upload');
const addTextBtn = document.getElementById('add-text');
const scaleControl = document.getElementById('scale-control');
const rotationControl = document.getElementById('rotation-control');
const colorControl = document.getElementById('color-control');
const fontControl = document.getElementById('font-control');
const fontSizeControl = document.getElementById('font-size-control');
const weightControl = document.getElementById('weight-control');
const layersField = document.getElementById('layers-field');
const previewField = document.getElementById('preview-field');
const form = document.getElementById('design-form');
const csrfToken = document.querySelector('input[name="csrf_token"]').value;

const layers = [];
let selectedLayerId = null;
let dragState = null;

function setTextControlsEnabled(enabled) {
    [colorControl, fontControl, fontSizeControl, weightControl].forEach((control) => {
        control.disabled = !enabled;
    });
}

setTextControlsEnabled(false);

function setSelectedLayer(id) {
    selectedLayerId = id;
    document.querySelectorAll('.design-layer').forEach((el) => {
        if (parseInt(el.dataset.layerId, 10) === id) {
            el.classList.add('selected');
        } else {
            el.classList.remove('selected');
        }
    });
    const layer = layers.find((item) => item.id === id);
    if (!layer) {
        return;
    }
    scaleControl.value = layer.scale;
    rotationControl.value = layer.rotation;
    if (layer.type === 'text') {
        setTextControlsEnabled(true);
        colorControl.value = layer.color || '#111111';
        fontControl.value = layer.font || 'Arial';
        fontSizeControl.value = layer.font_size || 32;
        weightControl.value = layer.font_weight || 600;
    } else {
        setTextControlsEnabled(false);
    }
}

function renderLayers() {
    board.innerHTML = '';
    layers.forEach((layer) => {
        let el;
        if (layer.type === 'image') {
            el = document.createElement('img');
            el.src = layer.content;
        } else {
            el = document.createElement('div');
            el.textContent = layer.content;
            el.style.color = layer.color || '#111111';
            el.style.fontFamily = layer.font || 'Arial';
            el.style.fontSize = `${layer.font_size || 32}px`;
            el.style.fontWeight = layer.font_weight || 600;
        }
        el.classList.add('design-layer');
        el.dataset.layerId = layer.id;
        const transform = `translate(-50%, -50%) scale(${layer.scale}) rotate(${layer.rotation}deg)`;
        el.style.left = `${layer.x}px`;
        el.style.top = `${layer.y}px`;
        el.style.transform = transform;
        el.addEventListener('mousedown', (event) => {
            event.preventDefault();
            setSelectedLayer(layer.id);
            const rect = board.getBoundingClientRect();
            dragState = {
                id: layer.id,
                offsetX: event.clientX - rect.left - layer.x,
                offsetY: event.clientY - rect.top - layer.y,
            };
        });
        board.appendChild(el);
    });
    if (selectedLayerId !== null) {
        setSelectedLayer(selectedLayerId);
    }
}

function addLayer(layer) {
    if (layers.length >= maxLayers) {
        alert(`Maximum of ${maxLayers} layers allowed.`);
        return;
    }
    layers.push(layer);
    setSelectedLayer(layer.id);
    renderLayers();
}

function generateLayerId() {
    return Date.now() + Math.floor(Math.random() * 1000);
}

imageUpload.addEventListener('change', async () => {
    const file = imageUpload.files[0];
    if (!file) {
        return;
    }
    const formData = new FormData();
    formData.append('action', 'upload_image');
    formData.append('csrf_token', csrfToken);
    formData.append('image', file);

    const response = await fetch('/client/customize', {
        method: 'POST',
        body: formData,
    });
    const data = await response.json();
    if (!data.ok) {
        alert(data.error || 'Image upload failed.');
        return;
    }
    addLayer({
        id: generateLayerId(),
        type: 'image',
        content: data.path,
        x: board.clientWidth / 2,
        y: board.clientHeight / 2,
        scale: 1,
        rotation: 0,
    });
    imageUpload.value = '';
});

addTextBtn.addEventListener('click', () => {
    const text = prompt('Enter your text layer:');
    if (!text) {
        return;
    }
    addLayer({
        id: generateLayerId(),
        type: 'text',
        content: text,
        x: board.clientWidth / 2,
        y: board.clientHeight / 2,
        scale: 1,
        rotation: 0,
        color: colorControl.value,
        font: fontControl.value,
        font_size: parseInt(fontSizeControl.value, 10) || 32,
        font_weight: parseInt(weightControl.value, 10) || 600,
    });
});

window.addEventListener('mousemove', (event) => {
    if (!dragState) {
        return;
    }
    const rect = board.getBoundingClientRect();
    const layer = layers.find((item) => item.id === dragState.id);
    if (!layer) {
        return;
    }
    layer.x = event.clientX - rect.left - dragState.offsetX;
    layer.y = event.clientY - rect.top - dragState.offsetY;
    renderLayers();
});

window.addEventListener('mouseup', () => {
    dragState = null;
});

[scaleControl, rotationControl, colorControl, fontControl, fontSizeControl, weightControl].forEach((control) => {
    control.addEventListener('input', () => {
        const layer = layers.find((item) => item.id === selectedLayerId);
        if (!layer) {
            return;
        }
        layer.scale = parseFloat(scaleControl.value);
        layer.rotation = parseFloat(rotationControl.value);
        if (layer.type === 'text') {
            layer.color = colorControl.value;
            layer.font = fontControl.value;
            layer.font_size = parseInt(fontSizeControl.value, 10) || 32;
            layer.font_weight = parseInt(weightControl.value, 10) || 600;
        }
        renderLayers();
    });
});

function serializeLayers() {
    return layers.map((layer) => ({
        type: layer.type,
        content: layer.content,
        x: layer.x,
        y: layer.y,
        scale: layer.scale,
        rotation: layer.rotation,
        color: layer.color || null,
        font: layer.font || null,
        font_size: layer.font_size || null,
        font_weight: layer.font_weight || null,
    }));
}

function generatePreview() {
    const canvas = document.createElement('canvas');
    canvas.width = board.clientWidth;
    canvas.height = board.clientHeight;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const drawLayer = (layer) => {
        ctx.save();
        ctx.translate(layer.x, layer.y);
        ctx.rotate((layer.rotation * Math.PI) / 180);
        ctx.scale(layer.scale, layer.scale);
        if (layer.type === 'image') {
            return new Promise((resolve) => {
                const img = new Image();
                img.onload = () => {
                    ctx.drawImage(img, -img.width / 2, -img.height / 2);
                    ctx.restore();
                    resolve();
                };
                img.src = layer.content;
            });
        }
        ctx.fillStyle = layer.color || '#111111';
        ctx.font = `${layer.font_weight || 600} ${layer.font_size || 32}px ${layer.font || 'Arial'}`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(layer.content, 0, 0);
        ctx.restore();
        return Promise.resolve();
    };

    return layers.reduce((promise, layer) => promise.then(() => drawLayer(layer)), Promise.resolve()).then(() => {
        return canvas.toDataURL('image/png');
    });
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (layers.length === 0) {
        alert('Add at least one layer before saving.');
        return;
    }
    if (layers.length > maxLayers) {
        alert(`Maximum of ${maxLayers} layers allowed.`);
        return;
    }
    layersField.value = JSON.stringify(serializeLayers());
    previewField.value = await generatePreview();
    form.submit();
});
</script>

<?php
require __DIR__ . '/../../includes/footer.php';
?>
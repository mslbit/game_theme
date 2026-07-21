# Folix Attr Batch

Batch import product attribute options via a textarea on the attribute edit page for Magento 2.

## Features

- Add multiple attribute options at once by pasting text into a textarea
- Supports **Select**, **Multiselect**, **Visual Swatch**, and **Text Swatch** attribute types
- Per-store-view values using the `Label|StoreId` syntax
- Pure frontend implementation — no server requests, works instantly

## Syntax

| Format | Description |
|---|---|
| `Label` | Admin (Store 0) value |
| `Label\|StoreId` | Value for a specific store view |
| `Red\|0,红色\|1,Rouge\|3` | Multiple stores in one option |

**Example input:**

```
Red
Blue
Red|0,红色|1,Rouge|3
Green|2
```

## Installation

### Via Composer (recommended)

```bash
composer require folix/module-attr-batch
bin/magento module:enable Folix_AttributeBatchImport
bin/magento setup:upgrade
```

### Manual installation

1. Copy the `Folix/AttributeBatchImport` directory to `app/code/`
2. Run:

```bash
bin/magento module:enable Folix_AttributeBatchImport
bin/magento setup:upgrade
```

## Usage

1. Go to **Stores → Attributes → Product**
2. Edit (or create) a Select / Multiselect / Swatch attribute
3. Scroll to the **Batch Import Options** panel
4. Paste your options into the textarea
5. Click **Add to Table**

## Compatibility

- Magento 2.4.6+
- PHP 8.1+

## License

MIT

# Folix Attr Batch — User Guide

## Overview

Folix Attr Batch is a Magento 2 extension that allows store administrators to batch import product attribute options via a simple textarea on the attribute edit page. Instead of adding options one by one, you can paste multiple options at once — including per-store-view values.

## Supported Attribute Types

- Select
- Multiselect
- Visual Swatch
- Text Swatch

The batch import panel automatically appears when editing a supported attribute type and hides otherwise.

## Installation

### Via Composer (recommended)

```bash
composer require folix/module-attr-batch
bin/magento module:enable Folix_AttributeBatchImport
bin/magento setup:upgrade
```

### Manual Installation

1. Download and extract the package
2. Copy the `Folix/AttributeBatchImport` directory to `app/code/`
3. Run the following commands:

```bash
bin/magento module:enable Folix_AttributeBatchImport
bin/magento setup:upgrade
bin/magento cache:flush
```

## Usage

1. Navigate to **Stores → Attributes → Product**
2. Click on an existing attribute to edit, or create a new one
3. Set **Catalog Input Type for Store Owner** to Select, Multiselect, Visual Swatch, or Text Swatch
4. Scroll down to the **Batch Import Options** panel
5. Type or paste your options into the textarea
6. Click **Add to Table**
7. The options will be added to the Manage Options table below

## Input Syntax

| Format | Description | Example |
|---|---|---|
| `Label` | Default Admin (Store 0) value | `Red` |
| `Label\|StoreId` | Value for a specific store view | `红色\|1` |
| `Label\|0,Label\|1,Label\|3` | Multiple store views in one line | `Red\|0,红色\|1,Rouge\|3` |

### Examples

**Simple options (Admin only):**

```
Red
Blue
Green
```

**Per-store-view values:**

```
Red|0,红色|1,Rouge|3
Blue|0,蓝色|1,Bleu|3
```

**Mixed:**

```
Red
Blue|0,蓝色|1
Green|2
```

## How It Works

The extension operates entirely on the frontend. When you click "Add to Table":

1. The textarea content is parsed into structured data
2. Empty option rows are created using Magento's native option management
3. The parsed values are filled into the corresponding input fields for each store view

No data is submitted to the server until you save the attribute form as usual.

## Compatibility

- Magento 2.4.6+
- PHP 8.1+

## Support

For issues and feature requests, visit:

https://github.com/folixio/module-attr-batch/issues

## License

MIT License. See LICENSE file for details.
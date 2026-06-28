async function fetchAsArrayBuffer(url) {
  const r = await fetch(url, { credentials: "include" });
  if (!r.ok) throw new Error(`Failed to load ${url}`);
  return r.arrayBuffer();
}

function dataUrlToUint8Array(dataUrl) {
  const s = String(dataUrl || "");
  const idx = s.indexOf("base64,");
  if (idx < 0) return new Uint8Array();
  const bin = atob(s.slice(idx + "base64,".length));
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return bytes;
}

function compressSignature(dataUrl, maxWidth = 600, quality = 0.6) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const scale = Math.min(1, maxWidth / img.width);
      const canvas = document.createElement("canvas");
      canvas.width  = Math.floor(img.width  * scale);
      canvas.height = Math.floor(img.height * scale);
      const ctx = canvas.getContext("2d");
      ctx.fillStyle = "#FFFFFF";
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      resolve(canvas.toDataURL("image/jpeg", quality));
    };
    img.onerror = reject;
    img.src = dataUrl;
  });
}

async function drawSignatureOnPdf(pdfDoc, signature, placement) {
  if (!signature) return;

  let imageBytes = null;
  let imageType  = null;

  if (signature.bytes && signature.bytes.length) {
    imageBytes = signature.bytes;
    if (imageBytes[0] === 0x89 && imageBytes[1] === 0x50 && imageBytes[2] === 0x4e && imageBytes[3] === 0x47) {
      imageType = "png";
    } else if (imageBytes[0] === 0xff && imageBytes[1] === 0xd8) {
      imageType = "jpg";
    }
  } else if (signature.dataUrl) {
    const compressed = await compressSignature(signature.dataUrl, 600, 0.6);
    imageBytes = dataUrlToUint8Array(compressed);
    imageType  = compressed.startsWith("data:image/jpeg") ? "jpg" : "png";
  }

  if (!imageBytes || !imageType) {
    console.error("Invalid signature format", signature);
    return;
  }

  const image = imageType === "jpg"
    ? await pdfDoc.embedJpg(imageBytes)
    : await pdfDoc.embedPng(imageBytes);

  const pages = pdfDoc.getPages();
  const pageIndex = Number.isFinite(placement.pageIndex) ? placement.pageIndex : 0;
  const page = pages[Math.max(0, Math.min(pageIndex, pages.length - 1))];

  page.drawImage(image, {
    x:      Number(placement.x      || 0),
    y:      Number(placement.y      || 0),
    width:  Number(placement.width  || 120),
    height: Number(placement.height || 50)
  });
}

async function drawSignaturesOnPdf(pdfDoc, signatures) {
  for (const s of (Array.isArray(signatures) ? signatures : [])) {
    if (s) await drawSignatureOnPdf(pdfDoc, s, s);
  }
}

async function fillPdfTemplate(opts) {
  try {
    const templateBytes = await fetchAsArrayBuffer(opts.templateUrl);
    const pdfDoc = await PDFLib.PDFDocument.load(templateBytes);

    pdfDoc.registerFontkit(window.fontkit);
    const fontBytes  = await fetch(`${BASE_PATH}/assets/fonts/Roboto-Regular.ttf`).then(r => r.arrayBuffer());
    const customFont = await pdfDoc.embedFont(fontBytes);

    const form     = pdfDoc.getForm();
    const fieldMap = (opts.fieldMap && typeof opts.fieldMap === "object") ? opts.fieldMap : {};

    for (const key of Object.keys(fieldMap)) {
      const value = fieldMap[key];
      if (value === undefined || value === null) continue;
      const v = String(value).normalize("NFC");

      try {
        const field = form.getField(key);
        const type  = field?.constructor?.name || "";

        if (type === "PDFTextField") { form.getTextField(key).setText(v); continue; }
        if (type === "PDFCheckBox")  { truthy(value) ? form.getCheckBox(key).check() : form.getCheckBox(key).uncheck(); continue; }
        if (type === "PDFDropdown")  { form.getDropdown(key).select(v); continue; }
        if (type === "PDFRadioGroup"){ form.getRadioGroup(key).select(v); continue; }

        try { form.getTextField(key).setText(v); } catch { console.warn("Unhandled field type", key, type); }
      } catch { console.warn("Field not found in PDF", key); }
    }

    form.updateFieldAppearances(customFont);

    if (Array.isArray(opts.signatures) && opts.signatures.length) {
      await drawSignaturesOnPdf(pdfDoc, opts.signatures);
    }

    form.flatten();
    const bytes = await pdfDoc.save();
    return { ok: true, fileName: opts.fileName, bytes };

  } catch (e) {
    console.error("fillPdfTemplate failed", e);
    return { ok: false };
  }
}

function safeFilePart(s) {
  return String(s)
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^\w]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 60);
}

function buildFileName(process, docKey) {
  const p       = (process && process.payload) ? process.payload : {};
  const personal = p && p.personal ? p.personal : {};
  const student  = safeText(personal.full_name) || safeText(personal.ist_id) || "student";
  return `${safeFilePart(student)}_${docKey}.pdf`;
}

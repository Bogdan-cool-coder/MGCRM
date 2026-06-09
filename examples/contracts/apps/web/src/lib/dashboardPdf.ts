"use client";

/**
 * Захват DOM-узла дашборда в PDF (Wave 2b).
 * html2canvas + jsPDF импортируются динамически, чтобы не тянуть их в SSR-бандл.
 * На время захвата форсим светлую тему (брендбук/печать), затем восстанавливаем.
 */
export async function exportDashboardToPdf(elementId: string): Promise<void> {
  const el = document.getElementById(elementId);
  if (!el) return;

  const [{ default: html2canvas }, jsPdfMod] = await Promise.all([
    import("html2canvas"),
    import("jspdf"),
  ]);
  const JsPDF = jsPdfMod.jsPDF;

  // Форсим светлую тему на время захвата
  const root = document.documentElement;
  const hadDark = root.classList.contains("dark");
  if (hadDark) root.classList.remove("dark");

  try {
    const canvas = await html2canvas(el, {
      scale: 2,
      useCORS: true,
      backgroundColor: "#ffffff",
      logging: false,
      windowWidth: el.scrollWidth,
    });

    const pdf = new JsPDF({ orientation: "portrait", unit: "mm", format: "a4" });
    const pageW = pdf.internal.pageSize.getWidth();
    const pageH = pdf.internal.pageSize.getHeight();

    const imgW = pageW;
    const imgH = (canvas.height * imgW) / canvas.width;

    let heightLeft = imgH;
    let position = 0;
    const imgData = canvas.toDataURL("image/png");

    pdf.addImage(imgData, "PNG", 0, position, imgW, imgH);
    heightLeft -= pageH;

    while (heightLeft > 0) {
      position -= pageH;
      pdf.addPage();
      pdf.addImage(imgData, "PNG", 0, position, imgW, imgH);
      heightLeft -= pageH;
    }

    const today = new Date().toISOString().slice(0, 10);
    pdf.save(`dashboard-${today}.pdf`);
  } finally {
    if (hadDark) root.classList.add("dark");
  }
}

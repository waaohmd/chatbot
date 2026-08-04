import "./globals.css";

export const metadata = {
  title: "Chatbot Admin",
  description: "Private local administration console for Mustdohr chatbot records.",
};

export default function RootLayout({ children }) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}

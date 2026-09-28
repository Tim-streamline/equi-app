export type PlusContent = {
  heroTitle: string; heroBody: string; price: string; period: string; heroButton: string;
  stepsTitle: string; stepsButton: string; steps: { title: string; body: string }[];
  guideTitle: string; guideBody: string; guideSignature: string;
  reviewsTitle: string; reviewsAreExamples: boolean; reviews: { title: string; quote: string; name: string }[];
  benefits: string[]; priceButton: string; priceNote: string;
  faqs: { question: string; answer: string }[]; closingTitle: string;
  confirmationTitle: string; confirmationPlan: string; confirmationProtocol: string; confirmationButton: string;
  welcomeTitle: string; welcomeBody: string;
};
export type PlusPageData = { content: PlusContent; heroImageUrl: string; portraitImageUrl: string | null };

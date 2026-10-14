import MoneyDocumentsList from '../../Components/MoneyDocumentsList';

export default function ReceiptsList(props) {
    return <MoneyDocumentsList kind="receipt" {...props} />;
}
